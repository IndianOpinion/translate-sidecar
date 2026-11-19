<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Admin;

use IndianOpinion\TranslateSidecar\Front\Preview;
use IndianOpinion\TranslateSidecar\Guard\GuardReport;
use IndianOpinion\TranslateSidecar\Language\Language;
use IndianOpinion\TranslateSidecar\Plugin;
use IndianOpinion\TranslateSidecar\Posts;
use IndianOpinion\TranslateSidecar\Routing;
use IndianOpinion\TranslateSidecar\Script\ScriptTable;
use IndianOpinion\TranslateSidecar\Settings;
use IndianOpinion\TranslateSidecar\Storage\Row;
use IndianOpinion\TranslateSidecar\Storage\Schema;

/**
 * Admin screens: review, settings with dashboard, the post editor box, row
 * and bulk actions, and notices. Every output is escaped; every action goes
 * through Actions, which checks a nonce and a capability.
 */
final class Admin
{
    public const PAGE_REVIEW = 'translate-sidecar';
    public const PAGE_SETTINGS = 'translate-sidecar-settings';
    public const PER_PAGE = 20;
    private const META_NONCE = 'translate_sidecar_meta';

    private Actions $actions;

    public function __construct(private readonly Plugin $plugin)
    {
        $this->actions = new Actions($plugin);
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_translate_sidecar', [$this, 'handlePost']);
        add_action('admin_notices', [$this, 'notices']);
        add_action('add_meta_boxes', [$this, 'metaBox']);
        add_action('save_post', [$this, 'savePostMeta'], 10, 2);
        add_filter('post_row_actions', [$this, 'rowActions'], 10, 2);
        add_filter('page_row_actions', [$this, 'rowActions'], 10, 2);
        foreach ($this->plugin->settings->postTypes() as $type) {
            add_filter('bulk_actions-edit-' . $type, [$this, 'bulkActions']);
            add_filter('handle_bulk_actions-edit-' . $type, [$this, 'handleBulk'], 10, 3);
        }
    }

    public function menu(): void
    {
        add_menu_page('Translations', 'Translations', Plugin::capability('review'), self::PAGE_REVIEW, [$this, 'renderReview'], 'dashicons-translation', 26);
        add_submenu_page(self::PAGE_REVIEW, 'Review translations', 'Review', Plugin::capability('review'), self::PAGE_REVIEW, [$this, 'renderReview']);
        add_submenu_page(self::PAGE_REVIEW, 'Translation settings', 'Settings', Plugin::capability('settings'), self::PAGE_SETTINGS, [$this, 'renderSettings']);
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function actionUrl(string $action, array $args): string
    {
        $url = add_query_arg(array_merge(['action' => 'translate_sidecar', 'ts_action' => $action], $args), admin_url('admin-post.php'));

        return wp_nonce_url($url, Actions::nonceAction($action));
    }

    private static function formOpen(string $action): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="translate-sidecar-form">'
            . '<input type="hidden" name="action" value="translate_sidecar" />'
            . '<input type="hidden" name="ts_action" value="' . esc_attr($action) . '" />'
            . wp_nonce_field(Actions::nonceAction($action), '_wpnonce', true, false);
    }

    /**
     * admin-post handler for forms and links.
     */
    public function handlePost(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- Actions::handle verifies the nonce.
        $in = wp_unslash($_REQUEST);
        $in = is_array($in) ? $in : [];
        $action = isset($in['ts_action']) && is_string($in['ts_action']) ? sanitize_key($in['ts_action']) : '';
        $result = $this->actions->handle($action, $in);
        set_transient('translate_sidecar_notice_' . get_current_user_id(), $result, 60);
        $back = wp_get_referer();
        wp_safe_redirect($back !== false ? $back : admin_url('admin.php?page=' . self::PAGE_REVIEW));
        exit;
    }

    public function notices(): void
    {
        $user = get_current_user_id();
        $notice = get_transient('translate_sidecar_notice_' . $user);
        if (is_array($notice) && isset($notice['message'])) {
            delete_transient('translate_sidecar_notice_' . $user);
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                !empty($notice['ok']) ? 'success' : 'error',
                esc_html((string) $notice['message'])
            );
        }
        if (!current_user_can(Plugin::capability('review'))) {
            return;
        }
        if (!Routing::prettyPermalinks()) {
            echo '<div class="notice notice-warning"><p>' . esc_html('translate-sidecar: pretty permalinks are off, so translation URLs, hreflang tags and translation sitemaps are disabled. Review and translation still work.') . '</p></div>';
        }
        $error = get_option(Schema::ERROR_OPTION, []);
        if (is_array($error) && $error !== []) {
            echo '<div class="notice notice-error"><p>' . esc_html('translate-sidecar: the database schema is incomplete (' . Schema::describe($error) . '). Every write is blocked. Deactivate and reactivate the plugin, or run "wp translate-sidecar verify-schema".') . '</p></div>';
        }
        if ($this->plugin->settings->creditPausedUntil() > time()) {
            echo '<div class="notice notice-warning"><p>' . esc_html('translate-sidecar: the translation provider reported exhausted credit. Calls are paused until ' . wp_date('Y-m-d H:i', $this->plugin->settings->creditPausedUntil()) . '.') . '</p></div>';
        }
    }

    public function metaBox(): void
    {
        foreach ($this->plugin->settings->postTypes() as $type) {
            add_meta_box('translate-sidecar', 'Translations', [$this, 'renderMetaBox'], $type, 'side');
        }
    }

    public function renderMetaBox(\WP_Post $post): void
    {
        $p = $this->plugin;
        wp_nonce_field(self::META_NONCE, '_translate_sidecar_meta_nonce');
        $skip = (bool) get_post_meta((int) $post->ID, Posts::META_SKIP, true);
        $source = (string) get_post_meta((int) $post->ID, Posts::META_SOURCE_LANG, true);
        echo '<p><label><input type="checkbox" name="translate_sidecar_skip" value="1"' . checked($skip, true, false) . ' /> ' . esc_html('Do not translate') . '</label></p>';
        echo '<p><label>' . esc_html('Written in') . ' <select name="translate_sidecar_source_lang"><option value="">' . esc_html('Site language (' . $p->settings->sourceLanguage() . ')') . '</option>';
        foreach ($p->registry->all() as $lang) {
            echo '<option value="' . esc_attr($lang->hreflang) . '"' . selected($source, $lang->hreflang, false) . '>' . esc_html($lang->name) . '</option>';
        }
        echo '</select></label></p>';
        if ($post->post_password !== '') {
            echo '<p>' . esc_html('This post is password protected, so its translations do not route.') . '</p>';
        }
        if (!current_user_can(Plugin::capability('request'))) {
            return;
        }
        echo '<ul>';
        foreach ($p->registry->all() as $prefix => $lang) {
            $rows = $p->store->rowsFor((int) $post->ID, $prefix);
            $states = array_values(array_unique(array_map(static fn (Row $r): string => $r->state, $rows)));
            $live = $p->translations->live((int) $post->ID, $prefix);
            $stale = $live !== null && $p->translations->isStale($live, $post);
            echo '<li><strong>' . esc_html($lang->name) . '</strong>: ' . esc_html($states === [] ? 'none' : implode(', ', $states)) . ($stale ? ' ' . esc_html('(stale)') : '');
            echo '<br /><a href="' . esc_url(self::actionUrl('request', ['post_id' => $post->ID, 'lang' => $prefix])) . '">' . esc_html('Request') . '</a>';
            echo ' | <a href="' . esc_url(self::actionUrl('request', ['post_id' => $post->ID, 'lang' => $prefix, 'dry_run' => 1])) . '">' . esc_html('Preview cost') . '</a>';
            if (in_array(Row::CANDIDATE, $states, true)) {
                echo ' | <a href="' . esc_url(self::actionUrl('request', ['post_id' => $post->ID, 'lang' => $prefix, 'replace' => 1])) . '">' . esc_html('Replace candidate') . '</a>';
            }
            if ($live !== null && current_user_can(Plugin::capability('review'))) {
                echo ' | <a href="' . esc_url(self::actionUrl('withdraw', ['row_id' => $live->id])) . '">' . esc_html('Withdraw') . '</a>';
            }
            if (in_array(Row::PRIOR, $states, true) && current_user_can(Plugin::capability('review'))) {
                echo ' | <a href="' . esc_url(self::actionUrl('rollback', ['post_id' => $post->ID, 'lang' => $prefix])) . '">' . esc_html('Roll back') . '</a>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    public function savePostMeta(mixed $postId, mixed $post = null): void
    {
        $postId = (int) $postId;
        $nonce = isset($_POST['_translate_sidecar_meta_nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['_translate_sidecar_meta_nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::META_NONCE) || !current_user_can('edit_post', $postId)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && constant('DOING_AUTOSAVE')) {
            return;
        }
        if (!empty($_POST['translate_sidecar_skip'])) {
            update_post_meta($postId, Posts::META_SKIP, 1);
        } else {
            delete_post_meta($postId, Posts::META_SKIP);
        }
        $source = isset($_POST['translate_sidecar_source_lang']) ? sanitize_text_field(wp_unslash((string) $_POST['translate_sidecar_source_lang'])) : '';
        if ($source !== '' && $this->plugin->registry->byHreflang($source) !== null) {
            update_post_meta($postId, Posts::META_SOURCE_LANG, $source);
        } else {
            delete_post_meta($postId, Posts::META_SOURCE_LANG);
        }
    }

    /**
     * @param mixed $actions
     * @return mixed
     */
    public function rowActions(mixed $actions, mixed $post): mixed
    {
        if (
            !is_array($actions) || !$post instanceof \WP_Post || !$this->plugin->posts->isTranslatableType((string) $post->post_type)
            || !current_user_can(Plugin::capability('request')) || $this->plugin->registry->all() === []
        ) {
            return $actions;
        }
        $actions['translate_sidecar'] = '<a href="' . esc_url(self::actionUrl('request', [
            'post_id' => $post->ID,
            'langs' => array_keys($this->plugin->registry->all()),
        ])) . '">' . esc_html('Request translations') . '</a>';

        return $actions;
    }

    /**
     * @param mixed $actions
     * @return mixed
     */
    public function bulkActions(mixed $actions): mixed
    {
        if (is_array($actions) && current_user_can(Plugin::capability('request'))) {
            $actions['translate_sidecar_request'] = 'Request translations';
        }

        return $actions;
    }

    /**
     * @param mixed $redirect
     * @param mixed $ids
     * @return mixed
     */
    public function handleBulk(mixed $redirect, mixed $action, mixed $ids): mixed
    {
        if ($action !== 'translate_sidecar_request' || !is_array($ids) || !current_user_can(Plugin::capability('request'))) {
            return $redirect;
        }
        $langs = array_keys($this->plugin->registry->all());
        $queued = 0;
        foreach ($ids as $id) {
            foreach ($this->plugin->requester->request((int) $id, $langs, ['source' => 'bulk', 'user_id' => get_current_user_id()]) as $r) {
                if (($r['status'] ?? '') === 'queued') {
                    $queued++;
                }
            }
        }
        set_transient('translate_sidecar_notice_' . get_current_user_id(), ['ok' => true, 'message' => sprintf('%d translation jobs queued.', $queued)], 60);

        return $redirect;
    }

    public function renderReview(): void
    {
        if (!current_user_can(Plugin::capability('review'))) {
            wp_die(esc_html('You are not allowed to review translations.'));
        }
        // phpcs:disable WordPress.Security.NonceVerification -- read-only screen parameters.
        $rowId = isset($_GET['row']) ? absint($_GET['row']) : 0;
        $lang = isset($_GET['lang']) ? sanitize_key(wp_unslash((string) $_GET['lang'])) : '';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        // phpcs:enable
        echo '<div class="wrap"><h1>' . esc_html('Review translations') . '</h1>';
        if ($rowId > 0) {
            $this->renderRow($rowId);
            echo '</div>';

            return;
        }
        echo '<p>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE_REVIEW)) . '">' . esc_html('All languages') . '</a>';
        foreach ($this->plugin->registry->routable() as $prefix => $l) {
            echo ' | <a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE_REVIEW . '&lang=' . $prefix)) . '">' . esc_html($l->name) . '</a>';
        }
        echo '</p>';
        $rows = $this->plugin->store->candidates($lang !== '' ? $lang : null, ($paged - 1) * self::PER_PAGE, self::PER_PAGE + 1);
        $more = count($rows) > self::PER_PAGE;
        $rows = array_slice($rows, 0, self::PER_PAGE);
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html('Post') . '</th><th>' . esc_html('Language') . '</th><th>' . esc_html('Adapter') . '</th><th>' . esc_html('Created (UTC)') . '</th><th>' . esc_html('Guard warnings') . '</th></tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td colspan="5">' . esc_html('No candidates are waiting.') . '</td></tr>';
        }
        foreach ($rows as $row) {
            $report = GuardReport::fromArray($row->guardReport);
            $link = admin_url('admin.php?page=' . self::PAGE_REVIEW . '&row=' . $row->id);
            printf(
                '<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%d</td></tr>',
                esc_url($link),
                esc_html((string) get_the_title($row->postId)),
                esc_html($row->lang),
                esc_html($row->adapter),
                esc_html((string) $row->createdGmt),
                count($report->warnings())
            );
        }
        echo '</tbody></table>';
        $base = admin_url('admin.php?page=' . self::PAGE_REVIEW . ($lang !== '' ? '&lang=' . $lang : ''));
        echo '<p>';
        if ($paged > 1) {
            echo '<a class="button" href="' . esc_url($base . '&paged=' . ($paged - 1)) . '">' . esc_html('Previous') . '</a> ';
        }
        if ($more) {
            echo '<a class="button" href="' . esc_url($base . '&paged=' . ($paged + 1)) . '">' . esc_html('Next') . '</a>';
        }
        echo '</p></div>';
    }

    /**
     * Side-by-side view of one row: source and translation by segment,
     * the guard report, and the diff against the live row.
     */
    private function renderRow(int $rowId): void
    {
        $p = $this->plugin;
        $row = $p->store->find($rowId);
        $post = $row !== null ? $p->posts->get($row->postId) : null;
        if ($row === null || $post === null) {
            echo '<p>' . esc_html('No such translation.') . '</p>';

            return;
        }
        $segmented = $p->posts->segmenter()->segment($p->posts->sourceFields($post));
        $live = $p->translations->live($row->postId, $row->lang);
        $liveTexts = [];
        foreach ($live !== null && $live->id !== $row->id ? $live->segments : [] as $s) {
            $liveTexts[$s['id']] = $s['text'];
        }
        $texts = [];
        foreach ($row->segments as $s) {
            $texts[$s['id']] = $s['text'];
        }
        printf('<h2>%s: %s (%s)</h2>', esc_html((string) get_the_title($post)), esc_html($row->lang), esc_html($row->state));
        $report = GuardReport::fromArray($row->guardReport);
        echo '<h3>' . esc_html('Guard report') . '</h3><ul>';
        foreach ($report->failures() as $f) {
            echo '<li><strong>' . esc_html('Failure') . '</strong> ' . esc_html($f['reason'] . ' ' . $f['field'] . ' ' . (string) wp_json_encode($f['detail'])) . '</li>';
        }
        foreach ($report->warnings() as $w) {
            echo '<li><strong>' . esc_html('Warning') . '</strong> ' . esc_html($w['reason'] . ' ' . $w['field'] . ' ' . (string) wp_json_encode($w['detail'])) . '</li>';
        }
        echo '<li>' . esc_html('Metrics: ' . (string) wp_json_encode($report->metrics())) . '</li></ul>';
        if ($row->state === Row::CANDIDATE) {
            $language = $p->registry->resolve($row->lang);
            if ($language !== null) {
                $url = add_query_arg(Preview::args(get_current_user_id(), $row->id, time()), $p->translations->buildUrl($post, $row->lang));
                echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url($url) . '">' . esc_html('Preview at its future URL') . '</a></p>';
            }
            echo self::formOpen('approve') . '<input type="hidden" name="row_id" value="' . esc_attr((string) $row->id) . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
            if ($report->hasWarnings() || !$report->passed()) {
                echo '<p><label><input type="checkbox" name="with_warnings" value="1" /> ' . esc_html('Approve with warnings') . '</label> <input type="text" name="reason" class="regular-text" placeholder="' . esc_attr('Reason (required)') . '" /></p>';
            }
            submit_button('Approve', 'primary', 'submit', false);
            echo '</form> ';
            echo self::formOpen('reject') . '<input type="hidden" name="row_id" value="' . esc_attr((string) $row->id) . '" /><input type="text" name="reason" placeholder="' . esc_attr('Reason (optional)') . '" /> '; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
            submit_button('Reject', 'secondary', 'submit', false);
            echo '</form>';
        }
        $editable = $row->state === Row::CANDIDATE;
        if ($editable) {
            echo self::formOpen('manual') // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
                . '<input type="hidden" name="row_id" value="' . esc_attr((string) $row->id) . '" />'
                . '<input type="hidden" name="post_id" value="' . esc_attr((string) $row->postId) . '" />'
                . '<input type="hidden" name="lang" value="' . esc_attr($row->lang) . '" />';
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html('Segment') . '</th><th>' . esc_html('Source') . '</th><th>' . esc_html('Translation') . '</th><th>' . esc_html('Live') . '</th></tr></thead><tbody>';
        foreach ($segmented->segments as $id => $seg) {
            $text = $texts[$id] ?? '';
            $liveText = $liveTexts[$id] ?? '';
            $changed = $liveTexts !== [] && $liveText !== $text;
            echo '<tr><td>' . esc_html($id) . ($changed ? ' *' : '') . '</td><td>' . esc_html($seg->text) . '</td><td>';
            if ($editable) {
                echo '<textarea name="segments[' . esc_attr($id) . ']" rows="3" class="large-text">' . esc_textarea($text) . '</textarea>';
            } else {
                echo esc_html($text);
            }
            echo '</td><td>' . esc_html($liveText) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ($editable) {
            submit_button('Save edits (guards run as warnings)', 'secondary', 'submit', false);
            echo '</form>';
        }
        echo '<p>' . esc_html('* differs from the live translation.') . '</p>';
    }

    public function renderSettings(): void
    {
        if (!current_user_can(Plugin::capability('settings'))) {
            wp_die(esc_html('You are not allowed to change translation settings.'));
        }
        $p = $this->plugin;
        $s = $p->settings->all();
        echo '<div class="wrap"><h1>' . esc_html('Translation settings') . '</h1>';
        (new Dashboard($p))->render();

        echo '<h2>' . esc_html('Kill switch') . '</h2>';
        $on = !$p->settings->isEnabled();
        echo self::formOpen('kill_switch') . '<input type="hidden" name="on" value="' . ($on ? '0' : '1') . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
        echo '<p>' . esc_html($on ? 'Calls are stopped. Review, routing, head tags and sitemaps keep working.' : 'Calls are allowed.') . '</p>';
        submit_button($on ? 'Allow calls' : 'Stop all calls', $on ? 'primary' : 'delete', 'submit', false);
        echo '</form>';

        echo '<h2>' . esc_html('Languages') . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html('Prefix') . '</th><th>hreflang</th><th>' . esc_html('Scripts') . '</th><th>' . esc_html('Publish') . '</th><th>' . esc_html('Index') . '</th><th>' . esc_html('Auto') . '</th><th></th></tr></thead><tbody>';
        foreach ($p->registry->all() as $prefix => $lang) {
            echo '<tr><td>' . esc_html($prefix) . '</td><td>' . esc_html($lang->hreflang) . '</td><td>' . esc_html($lang->noScriptGuard ? 'no script guard' : implode(', ', $lang->scripts)) . '</td>';
            foreach (['publish', 'index', 'auto'] as $switch) {
                $value = $lang->{$switch};
                echo '<td><a href="' . esc_url(self::actionUrl('set_switch', ['lang' => $prefix, 'switch' => $switch, 'on' => $value ? 0 : 1])) . '">' . esc_html($value ? 'on (turn off)' : 'off (turn on)') . '</a></td>';
            }
            echo '<td><a href="' . esc_url(self::actionUrl('remove_language', ['lang' => $prefix])) . '">' . esc_html('Remove') . '</a></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p>' . esc_html('Turn on indexing for one language at a time, after reviewing a sample. Removing a language keeps its rows until you purge them.') . '</p>';
        $this->renderLanguageForm();

        echo '<h3>' . esc_html('Purge a language') . '</h3>' . self::formOpen('purge_language'); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
        echo '<p><input type="text" name="lang" placeholder="prefix" /> <input type="text" name="confirm" placeholder="' . esc_attr('type the prefix again') . '" /></p>';
        submit_button('Purge rows', 'delete', 'submit', false);
        echo '</form>';

        echo '<h2>' . esc_html('Settings') . '</h2>' . self::formOpen('save_settings'); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
        echo '<table class="form-table">';
        $adapters = ['' => 'None'];
        foreach ($p->translators->all() as $id => $t) {
            $adapters[$id] = $t->label();
        }
        self::select('adapter', 'Adapter', (string) $s['adapter'], $adapters, 'Post text (title, body, excerpt, alt text) is sent to the chosen provider. Nothing else: no author names, no e-mail, no comments, no user data.');
        self::text('source_lang', 'Source language (BCP 47)', (string) $s['source_lang'], 'Empty means the site locale: ' . $p->settings->sourceLanguage());
        self::text('source_prefix', 'Source language prefix (optional)', (string) $s['source_prefix'], 'Only needed to route translations of posts written natively in another language.');
        self::checkbox('source_publish', 'Publish source prefix routes', (bool) $s['source_publish']);
        self::checkbox('source_index', 'Index source prefix routes', (bool) $s['source_index']);
        self::text('post_types', 'Post types (comma separated)', implode(',', (array) $s['post_types']));
        self::number('min_words', 'Minimum source words', (int) $s['min_words']);
        self::number('max_segments', 'Maximum segments per job', (int) $s['max_segments']);
        self::number('segment_budget', 'Source characters per call', (int) $s['segment_budget']);
        self::number('daily_cap', 'Daily call cap', $p->settings->dailyCap(), defined('TRANSLATE_SIDECAR_DAILY_CAP') ? 'Set by the TRANSLATE_SIDECAR_DAILY_CAP constant.' : '0 means no calls.');
        self::number('daily_chars', 'Daily character cap (0 = off)', (int) $s['daily_chars']);
        self::select('stale_policy', 'When the original changes', (string) $s['stale_policy'], ['serve' => 'Keep serving (serve)', 'hold_major' => 'Hold on a major change (hold_major)', 'hold' => 'Hold on any change (hold)']);
        self::checkbox('translate_alt', 'Translate image alt text and captions', (bool) $s['translate_alt']);
        self::select('numbers_guard', 'Missing numbers', (string) $s['numbers_guard'], ['warn' => 'Warn', 'fail' => 'Fail']);
        foreach (['title_share' => 'Script share, title', 'body_share' => 'Script share, body', 'third_script' => 'Third-script ceiling', 'ratio_min' => 'Length ratio minimum', 'ratio_max' => 'Length ratio maximum', 'unchanged_ceiling' => 'Unchanged ceiling'] as $key => $label) {
            self::text($key, $label, (string) $s[$key]);
        }
        self::select('head_mode', 'Head output', (string) $s['head_mode'], ['auto' => 'Detect', 'core' => 'core', 'filters' => 'filters', 'rewrite' => 'rewrite'], 'Active now: ' . $p->head->mode());
        self::number('sitemap_max', 'Sitemap URLs per file', (int) $s['sitemap_max']);
        self::checkbox('sitemap_alternates', 'Alternates in sitemap entries', (bool) $s['sitemap_alternates']);
        self::number('tick_interval', 'Tick interval (seconds)', (int) $s['tick_interval']);
        self::number('jobs_per_tick', 'Jobs per tick', (int) $s['jobs_per_tick']);
        self::number('time_budget', 'Tick time budget (seconds)', (int) $s['time_budget']);
        self::checkbox('use_queue', 'Use gen-queue when present', (bool) $s['use_queue']);
        self::number('retention_days', 'Keep failed and rejected rows (days)', (int) $s['retention_days']);
        self::number('keep_versions', 'Prior versions kept for rollback', (int) $s['keep_versions']);
        self::checkbox('self_approval', 'Allow self-approval', (bool) $s['self_approval']);
        self::text('do_not_translate', 'Do-not-translate terms (comma separated)', implode(',', (array) $s['do_not_translate']));
        echo '</table>';
        submit_button('Save settings');
        echo '</form></div>';
    }

    private function renderLanguageForm(): void
    {
        echo '<h3>' . esc_html('Add or edit a language') . '</h3>' . self::formOpen('save_language'); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
        echo '<table class="form-table">';
        $fields = [
            'prefix' => 'URL prefix (for example "fr" or "pt-br")',
            'hreflang' => 'hreflang tag (BCP 47, for example "pt-BR")',
            'locale' => 'Open Graph locale (for example "pt_BR")',
            'name' => 'Name',
            'native_name' => 'Native name',
            'scripts' => 'Scripts (comma separated, empty for the default)',
            'original_link_label' => '"Read the original" label in this language',
            'ratio_min' => 'Length ratio minimum (optional)',
            'ratio_max' => 'Length ratio maximum (optional)',
            'adapter' => 'Adapter for this language (optional)',
        ];
        foreach ($fields as $key => $label) {
            echo '<tr><th><label>' . esc_html($label) . '</label></th><td><input type="text" class="regular-text" name="language[' . esc_attr($key) . ']" /></td></tr>';
        }
        echo '<tr><th>' . esc_html('Direction') . '</th><td><select name="language[dir]"><option value="ltr">ltr</option><option value="rtl">rtl</option></select></td></tr>';
        echo '<tr><th>' . esc_html('No script guard') . '</th><td><label><input type="checkbox" name="language[no_script_guard]" value="1" /> ' . esc_html('Turns off the script check for this language.') . '</label></td></tr>';
        echo '<tr><th>' . esc_html('Known scripts') . '</th><td>' . esc_html(implode(', ', array_keys(ScriptTable::defaults()))) . '</td></tr>';
        echo '</table><p>' . esc_html('New languages start with publish, index and auto off.') . '</p>';
        echo '<input type="hidden" name="replacing" value="" />';
        submit_button('Save language', 'secondary');
        echo '</form>';
    }

    private static function row(string $label, string $control, string $help = ''): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $control // phpcs:ignore WordPress.Security.EscapeOutput -- controls are escaped by the callers.
            . ($help !== '' ? '<p class="description">' . esc_html($help) . '</p>' : '') . '</td></tr>';
    }

    private static function text(string $key, string $label, string $value, string $help = ''): void
    {
        self::row($label, '<input type="text" class="regular-text" name="settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" />', $help);
    }

    private static function number(string $key, string $label, int $value, string $help = ''): void
    {
        self::row($label, '<input type="number" min="0" name="settings[' . esc_attr($key) . ']" value="' . esc_attr((string) $value) . '" />', $help);
    }

    private static function checkbox(string $key, string $label, bool $value): void
    {
        self::row($label, '<input type="checkbox" name="settings[' . esc_attr($key) . ']" value="1"' . checked($value, true, false) . ' />');
    }

    /**
     * @param array<string, string> $options
     */
    private static function select(string $key, string $label, string $value, array $options, string $help = ''): void
    {
        $html = '<select name="settings[' . esc_attr($key) . ']">';
        foreach ($options as $k => $l) {
            $html .= '<option value="' . esc_attr((string) $k) . '"' . selected($value, (string) $k, false) . '>' . esc_html($l) . '</option>';
        }
        self::row($label, $html . '</select>', $help);
    }
}
