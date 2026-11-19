<?php

/**
 * WordPress function stubs: just enough behaviour for the plugin's tests.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects, Squiz.Functions.GlobalFunction

const OBJECT = 'OBJECT';
const ARRAY_A = 'ARRAY_A';
const DAY_IN_SECONDS = 86400;

function add_filter(string $hook, mixed $cb, int $priority = 10, int $args = 1): bool
{
    WpStub::$hooks[$hook][$priority][] = [$cb, $args];
    $GLOBALS['wp_filter'][$hook] = (object) ['callbacks' => array_map(
        static fn (array $list): array => array_map(static fn (array $e): array => ['function' => $e[0], 'accepted_args' => $e[1]], $list),
        WpStub::$hooks[$hook]
    )];

    return true;
}

function add_action(string $hook, mixed $cb, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $cb, $priority, $args);
}

function remove_action(string $hook, mixed $cb, int $priority = 10): bool
{
    foreach (WpStub::$hooks[$hook] ?? [] as $p => $list) {
        foreach ($list as $i => $entry) {
            if ($entry[0] === $cb) {
                unset(WpStub::$hooks[$hook][$p][$i]);
            }
        }
    }
    if (isset($GLOBALS['wp_filter'][$hook])) {
        foreach ($GLOBALS['wp_filter'][$hook]->callbacks as $p => $list) {
            foreach ($list as $i => $entry) {
                if ($entry['function'] === $cb) {
                    unset($GLOBALS['wp_filter'][$hook]->callbacks[$p][$i]);
                }
            }
        }
    }

    return true;
}

function has_filter(string $hook): bool
{
    foreach (WpStub::$hooks[$hook] ?? [] as $list) {
        if ($list !== []) {
            return true;
        }
    }

    return false;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    $hooks = WpStub::$hooks[$hook] ?? [];
    ksort($hooks);
    foreach ($hooks as $list) {
        foreach ($list as [$cb, $n]) {
            $value = $cb(...array_slice(array_merge([$value], $args), 0, max(1, $n)));
        }
    }

    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    WpStub::$actionCounts[$hook] = (WpStub::$actionCounts[$hook] ?? 0) + 1;
    WpStub::$fired[$hook][] = $args;
    $hooks = WpStub::$hooks[$hook] ?? [];
    ksort($hooks);
    foreach ($hooks as $list) {
        foreach ($list as [$cb, $n]) {
            $cb(...array_slice($args, 0, $n));
        }
    }
}

function did_action(string $hook): int
{
    return WpStub::$actionCounts[$hook] ?? 0;
}

function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WpStub::$options) ? WpStub::$options[$name] : $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    WpStub::$options[$name] = $value;

    return true;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = null): bool
{
    if (array_key_exists($name, WpStub::$options)) {
        return false;
    }
    WpStub::$options[$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    unset(WpStub::$options[$name]);

    return true;
}

function get_site_option(string $name, mixed $default = false): mixed
{
    return WpStub::$siteOptions[$name] ?? $default;
}

function update_site_option(string $name, mixed $value): bool
{
    WpStub::$siteOptions[$name] = $value;

    return true;
}

function delete_site_option(string $name): bool
{
    unset(WpStub::$siteOptions[$name]);

    return true;
}

function is_multisite(): bool
{
    return WpStub::$multisite;
}

function get_current_blog_id(): int
{
    return WpStub::$blogId;
}

function switch_to_blog(int $id): bool
{
    WpStub::$blogs[WpStub::$blogId]['options'] = WpStub::$options;
    WpStub::$blogs[WpStub::$blogId]['prev'] = WpStub::$blogId;
    $prev = WpStub::$blogId;
    WpStub::$blogId = $id;
    WpStub::$options = WpStub::$blogs[$id]['options'] ?? ['permalink_structure' => '/%postname%/'];
    WpStub::$blogs[$id]['back'] = $prev;
    do_action('switch_blog', $id, $prev);

    return true;
}

function restore_current_blog(): bool
{
    $back = WpStub::$blogs[WpStub::$blogId]['back'] ?? 1;

    return switch_to_blog((int) $back);
}

/**
 * @return list<int>
 */
function get_sites(array $args = []): array
{
    $ids = array_keys(WpStub::$blogs);
    $ids[] = 1;

    return array_values(array_unique(array_map('intval', $ids)));
}

function wp_cache_delete(mixed $key, string $group = ''): bool
{
    return true;
}

function wp_cache_flush(): bool
{
    return true;
}

function get_locale(): string
{
    return WpStub::$locale;
}

function get_post(mixed $post = null): ?WP_Post
{
    if ($post instanceof WP_Post) {
        return $post;
    }

    return WpStub::$posts[(int) $post] ?? null;
}

function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    $values = WpStub::$meta[$id][$key] ?? [];

    return $single ? ($values[0] ?? '') : $values;
}

function update_post_meta(int $id, string $key, mixed $value): bool
{
    WpStub::$meta[$id][$key] = [$value];

    return true;
}

function add_post_meta(int $id, string $key, mixed $value): bool
{
    WpStub::$meta[$id][$key][] = $value;

    return true;
}

function delete_post_meta(int $id, string $key): bool
{
    unset(WpStub::$meta[$id][$key]);

    return true;
}

/**
 * @return list<WP_Post>
 */
function get_posts(array $args = []): array
{
    WpStub::$getPostsCalls++;
    $types = (array) ($args['post_type'] ?? ['post']);
    $status = $args['post_status'] ?? 'publish';
    $out = [];
    foreach (WpStub::$posts as $post) {
        if (!in_array($post->post_type, $types, true) && !in_array('any', $types, true)) {
            continue;
        }
        if ($status !== 'any' && !in_array($post->post_status, (array) $status, true)) {
            continue;
        }
        if (isset($args['post__in']) && !in_array($post->ID, (array) $args['post__in'], true)) {
            continue;
        }
        if (isset($args['post_name__in']) && !in_array($post->post_name, (array) $args['post_name__in'], true)) {
            continue;
        }
        if (isset($args['meta_key'])) {
            $values = WpStub::$meta[$post->ID][(string) $args['meta_key']] ?? [];
            if (!in_array($args['meta_value'] ?? null, $values, true)) {
                continue;
            }
        }
        $out[] = $post;
    }
    if (isset($args['post__in']) && ($args['orderby'] ?? '') === 'post__in') {
        $order = array_flip(array_map('intval', (array) $args['post__in']));
        usort($out, static fn (WP_Post $a, WP_Post $b): int => $order[$a->ID] <=> $order[$b->ID]);
    }

    return $out;
}

function get_page_by_path(string $path, string $output = OBJECT, mixed $types = 'page'): ?WP_Post
{
    $types = (array) $types;
    $parts = array_values(array_filter(explode('/', trim($path, '/'))));
    if ($parts === []) {
        return null;
    }
    $leaf = array_pop($parts);
    foreach (WpStub::$posts as $post) {
        if ($post->post_name !== $leaf || !in_array($post->post_type, $types, true)) {
            continue;
        }
        $parent = $post->post_parent;
        $ok = true;
        foreach (array_reverse($parts) as $segment) {
            $p = WpStub::$posts[$parent] ?? null;
            if ($p === null || $p->post_name !== $segment) {
                $ok = false;
                break;
            }
            $parent = $p->post_parent;
        }
        if ($ok && ($parts !== [] || $parent === 0 || $post->post_parent === 0)) {
            return $post;
        }
    }

    return null;
}

function get_term_by(string $field, mixed $value, string $taxonomy = ''): object|false
{
    $id = $field === 'slug' ? (WpStub::$terms[$taxonomy][(string) $value] ?? 0) : 0;

    return $id > 0 ? (object) ['term_id' => $id, 'slug' => (string) $value, 'taxonomy' => $taxonomy] : false;
}

function get_page_uri(mixed $post): string
{
    $post = get_post($post);
    if ($post === null) {
        return '';
    }
    $uri = $post->post_name;
    $parent = $post->post_parent;
    while ($parent > 0 && isset(WpStub::$posts[$parent])) {
        $uri = WpStub::$posts[$parent]->post_name . '/' . $uri;
        $parent = WpStub::$posts[$parent]->post_parent;
    }

    return $uri;
}

function get_post_type_object(string $type): ?object
{
    return (object) ['name' => $type, 'hierarchical' => in_array($type, ['page', 'guide'], true)];
}

function get_permalink(mixed $post = 0): string|false
{
    $post = get_post($post);
    if ($post === null) {
        return false;
    }
    if ($post->post_status !== 'publish') {
        return home_url('/?p=' . $post->ID);
    }

    return home_url(user_trailingslashit(get_page_uri($post)));
}

function home_url(string $path = ''): string
{
    $base = 'https://example.test';
    if ($path === '') {
        return $base;
    }

    return $base . '/' . ltrim($path, '/');
}

function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . ltrim($path, '/');
}

function user_trailingslashit(string $s, string $type = ''): string
{
    return WpStub::$trailingSlash ? rtrim($s, '/') . '/' : rtrim($s, '/');
}

function wpautop(string $text): string
{
    if (trim($text) === '') {
        return '';
    }
    $chunks = preg_split('/\n\s*\n/', trim($text)) ?: [];
    $out = '';
    foreach ($chunks as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') {
            continue;
        }
        if (preg_match('/^<(h[1-6]|ul|ol|pre|figure|table|blockquote|div|p)\b/i', $chunk) === 1) {
            $out .= $chunk . "\n";
        } else {
            $out .= '<p>' . $chunk . "</p>\n";
        }
    }

    return $out;
}

function wp_kses_post(string $html): string
{
    $html = (string) preg_replace('#<(script|style|iframe)\b[^>]*>.*?</\1>#si', '', $html);

    return (string) preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html);
}

function esc_html(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function esc_attr(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function esc_textarea(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function esc_url(mixed $s): string
{
    return str_replace(['"', "'", '<', '>'], ['%22', '%27', '%3C', '%3E'], (string) $s);
}

function esc_url_raw(mixed $s): string
{
    return esc_url($s);
}

function wp_json_encode(mixed $v, int $flags = 0): string|false
{
    return json_encode($v, $flags);
}

function clean_post_cache(mixed $id): void
{
    WpStub::$cleaned[] = (int) ($id instanceof WP_Post ? $id->ID : $id);
}

function flush_rewrite_rules(bool $hard = true): void
{
    WpStub::$flushes++;
}

function add_rewrite_rule(string $regex, string $query, string $after = 'bottom'): void
{
    WpStub::$rewriteRules[] = [$regex, $query, $after];
}

function is_admin(): bool
{
    return WpStub::$admin;
}

function get_the_ID(): int
{
    return WpStub::$currentPostId;
}

function get_bloginfo(string $show = ''): string
{
    return (string) (WpStub::$options['blogname'] ?? '');
}

function current_user_can(string $cap, mixed ...$args): bool
{
    return in_array($cap, WpStub::$caps[WpStub::$currentUser] ?? [], true);
}

function get_current_user_id(): int
{
    return WpStub::$currentUser;
}

function is_user_logged_in(): bool
{
    return WpStub::$currentUser > 0;
}

function wp_create_nonce(string $action = ''): string
{
    return substr(hash('sha256', $action . '|' . WpStub::$currentUser), 0, 10);
}

function wp_verify_nonce(string $nonce, string $action = ''): int|false
{
    return hash_equals(wp_create_nonce($action), $nonce) ? 1 : false;
}

function wp_nonce_url(string $url, string $action = ''): string
{
    return add_query_arg(['_wpnonce' => wp_create_nonce($action)], $url);
}

function wp_nonce_field(string $action = '', string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
{
    return '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce($action) . '" />';
}

function add_query_arg(array $args, string $url = ''): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone(WpStub::$timezone);
}

function wp_date(string $format, ?int $ts = null): string
{
    return (new DateTimeImmutable('@' . ($ts ?? time())))->setTimezone(wp_timezone())->format($format);
}

function sanitize_title(string $s): string
{
    return strtolower((string) preg_replace('/[^a-z0-9-]+/i', '-', $s));
}

function sanitize_key(string $s): string
{
    return strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $s));
}

function sanitize_text_field(string $s): string
{
    return trim(strip_tags($s));
}

function wp_unslash(mixed $v): mixed
{
    return $v;
}

function absint(mixed $v): int
{
    return abs((int) $v);
}

function is_wp_error(mixed $v): bool
{
    return $v instanceof WP_Error;
}

function status_header(int $code): void
{
    WpStub::$statuses[] = $code;
}

function nocache_headers(): void
{
}

function wp_safe_redirect(string $location, int $status = 302, string $by = ''): bool
{
    WpStub::$redirects[] = [$location, $status];

    return true;
}

function get_query_var(string $name, mixed $default = ''): mixed
{
    return WpStub::$queryVars[$name] ?? $default;
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'test-salt-' . $scheme;
}

function is_singular(): bool
{
    return WpStub::$singular;
}

function get_queried_object_id(): int
{
    return WpStub::$queriedId;
}

function get_the_title(mixed $post = 0): string
{
    $post = get_post($post);

    return $post !== null ? $post->post_title : '';
}

function get_post_time(string $format, bool $gmt, mixed $post): string
{
    $post = get_post($post);

    return $post !== null ? (new DateTimeImmutable($post->post_date_gmt, new DateTimeZone('UTC')))->format($format) : '';
}

function get_post_modified_time(string $format, bool $gmt, mixed $post): string
{
    $post = get_post($post);

    return $post !== null ? (new DateTimeImmutable($post->post_modified_gmt, new DateTimeZone('UTC')))->format($format) : '';
}

function get_the_author_meta(string $field, int $user): string
{
    return 'Author ' . $user;
}

function get_the_post_thumbnail_url(mixed $post, string $size = ''): string|false
{
    $post = get_post($post);
    if ($post === null) {
        return false;
    }
    $thumb = WpStub::$meta[$post->ID]['_thumbnail_url'][0] ?? '';

    return $thumb !== '' ? (string) $thumb : false;
}

function wp_next_scheduled(string $hook): int|false
{
    return WpStub::$scheduled[$hook] ?? false;
}

function wp_schedule_event(int $ts, string $recurrence, string $hook): bool
{
    WpStub::$scheduled[$hook] = $ts;

    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    unset(WpStub::$scheduled[$hook]);

    return 1;
}

function get_transient(string $key): mixed
{
    return WpStub::$transients[$key] ?? false;
}

function set_transient(string $key, mixed $value, int $ttl = 0): bool
{
    WpStub::$transients[$key] = $value;

    return true;
}

function delete_transient(string $key): bool
{
    unset(WpStub::$transients[$key]);

    return true;
}

function register_block_type(string $name, array $args = []): bool
{
    WpStub::$blocks[] = ['name' => $name] + $args;

    return true;
}

function wp_sitemaps_get_server(): object
{
    return new class () {
        public function sitemaps_enabled(): bool
        {
            return WpStub::$sitemapsEnabled;
        }
    };
}

function dbDelta(array|string $queries): array
{
    global $wpdb;
    foreach ((array) $queries as $sql) {
        if (preg_match('/CREATE TABLE (\S+) \((.*)\)/s', $sql, $m) === 1 && $wpdb instanceof FakeWpdb) {
            $cols = [];
            foreach (explode("\n", $m[2]) as $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^(PRIMARY|KEY|UNIQUE)/', $line) === 1) {
                    continue;
                }
                $cols[] = (string) strtok($line, ' ');
            }
            // Simulates dbDelta silently skipping columns listed in skipColumns.
            $skip = $GLOBALS['dbdelta_skip'] ?? [];
            $existing = $wpdb->columns[$m[1]] ?? [];
            $wpdb->columns[$m[1]] = array_values(array_unique(array_merge($existing, array_diff($cols, $skip))));
        }
    }

    return [];
}

function submit_button(string $text = 'Save', string $type = 'primary', string $name = 'submit', bool $wrap = true): void
{
    echo '<button type="submit" class="button button-' . esc_attr($type) . '" name="' . esc_attr($name) . '">' . esc_html($text) . '</button>';
}

function checked(mixed $a, mixed $b = true, bool $echo = true): string
{
    return (string) $a === (string) $b ? ' checked="checked"' : '';
}

function selected(mixed $a, mixed $b = true, bool $echo = true): string
{
    return (string) $a === (string) $b ? ' selected="selected"' : '';
}

function wp_die(string $message = ''): void
{
    throw new RuntimeException('wp_die: ' . $message);
}

function rel_canonical(): void
{
}

function wp_generator(): void
{
}

function __return_true(): bool
{
    return true;
}

function register_rest_route(string $namespace, string $route, array $args = []): bool
{
    $GLOBALS['__routes'][$route] = $args;

    return true;
}
