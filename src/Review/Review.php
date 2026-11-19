<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Review;

use IndianOpinion\TranslateSidecar\Guard\GuardReport;
use IndianOpinion\TranslateSidecar\Job\GuardsFactory;
use IndianOpinion\TranslateSidecar\Language\Registry;
use IndianOpinion\TranslateSidecar\Posts;
use IndianOpinion\TranslateSidecar\Purger;
use IndianOpinion\TranslateSidecar\Segment\PlaceholderException;
use IndianOpinion\TranslateSidecar\Segment\Reassembler;
use IndianOpinion\TranslateSidecar\Segment\Segmenter;
use IndianOpinion\TranslateSidecar\Settings;
use IndianOpinion\TranslateSidecar\Storage\Row;
use IndianOpinion\TranslateSidecar\Storage\Store;
use IndianOpinion\TranslateSidecar\Support\Clock;
use IndianOpinion\TranslateSidecar\Translations;

/**
 * State changes made by people: approve, reject, withdraw, roll back, and
 * manual translations (cases 53 to 62). Every change is a conditional
 * update on the current state under a per post and language lock.
 */
final class Review
{
    public function __construct(
        private readonly Store $store,
        private readonly Registry $registry,
        private readonly Settings $settings,
        private readonly Posts $posts,
        private readonly Translations $translations,
        private readonly Purger $purger,
        private readonly GuardsFactory $guards,
        private readonly Clock $clock,
    ) {
    }

    private static function lockName(int $postId, string $lang): string
    {
        return 'row_' . $postId . '_' . $lang;
    }

    /**
     * @param array{with_warnings?:bool, reason?:string} $args
     */
    public function approve(int $rowId, int $userId, array $args = []): bool|\WP_Error
    {
        $row = $this->store->find($rowId);
        if ($row === null) {
            return new \WP_Error('not_found', 'No such translation.');
        }
        if ($row->state === Row::LIVE) {
            return new \WP_Error('already_approved', 'This translation is already approved.');
        }
        if ($row->state !== Row::CANDIDATE) {
            return new \WP_Error('invalid_state', sprintf('Only candidates can be approved; this one is %s.', $row->state));
        }
        $allowSelf = (bool) apply_filters('translate_sidecar_allow_self_approval', $this->settings->bool('self_approval'), $userId, $rowId);
        if (!$allowSelf && $row->createdBy !== 0 && $row->createdBy === $userId) {
            return new \WP_Error('self_approval', 'Self-approval is turned off. Ask another reviewer.');
        }
        $report = GuardReport::fromArray($row->guardReport);
        $reasonText = trim((string) ($args['reason'] ?? ''));
        if ($report->hasWarnings() || !$report->passed()) {
            if (empty($args['with_warnings'])) {
                return new \WP_Error('needs_confirmation', 'This translation has guard warnings. Tick "approve with warnings" and give a reason.');
            }
            if ($reasonText === '') {
                return new \WP_Error('reason_required', 'Give a reason for approving with warnings.');
            }
        }

        $lock = self::lockName($row->postId, $row->lang);
        if (!$this->store->lock($lock, 30)) {
            return new \WP_Error('busy', 'Another change to this translation is in progress. Try again.');
        }
        $now = $this->clock->gmt();
        try {
            $current = $this->store->find($rowId);
            if ($current === null || $current->state !== Row::CANDIDATE) {
                return new \WP_Error('already_approved', 'This translation was changed by someone else.');
            }
            $previous = $this->store->rowsFor($row->postId, $row->lang, [Row::LIVE]);
            foreach ($previous as $old) {
                $this->store->update($old->id, ['state' => Row::PRIOR, 'is_prior' => 1, 'updated_gmt' => $now], Row::LIVE);
            }
            $changes = ['state' => Row::LIVE, 'approved_gmt' => $now, 'approved_by' => $userId, 'updated_gmt' => $now, 'is_prior' => 0];
            if ($reasonText !== '' && !empty($args['with_warnings'])) {
                $changes['reason'] = 'approved_with_warnings';
                $changes['note'] = $reasonText;
            }
            if (!$this->store->update($rowId, $changes, Row::CANDIDATE)) {
                foreach ($previous as $old) {
                    $this->store->update($old->id, ['state' => Row::LIVE, 'is_prior' => 0], Row::PRIOR);
                }

                return new \WP_Error('already_approved', 'This translation was changed by someone else.');
            }
            $this->trimPriors($row->postId, $row->lang);
            $memory = [];
            foreach ($row->segments as $seg) {
                if ($seg['hash'] !== '' && $seg['text'] !== '') {
                    $memory[$seg['hash']] = $seg['text'];
                }
            }
            if ($memory !== []) {
                $this->store->memoryPut($row->lang, $memory, $rowId, $now);
            }
        } finally {
            $this->store->unlock($lock);
        }
        $this->purger->purge($row->postId, 'approve', [$row->lang]);
        do_action('translate_sidecar_approved', $rowId, $row->postId, $row->lang);

        return true;
    }

    /**
     * Keeps the newest N prior versions; older ones are marked rejected so
     * retention removes them.
     */
    private function trimPriors(int $postId, string $lang): void
    {
        $keep = max(1, $this->settings->int('keep_versions'));
        $priors = $this->store->rowsFor($postId, $lang, [Row::PRIOR]);
        usort($priors, static fn (Row $a, Row $b): int => [(string) $b->updatedGmt, $b->id] <=> [(string) $a->updatedGmt, $a->id]);
        foreach (array_slice($priors, $keep) as $old) {
            $this->store->update($old->id, ['state' => Row::REJECTED, 'is_prior' => 0, 'reason' => 'expired_prior', 'updated_gmt' => $this->clock->gmt()], Row::PRIOR);
        }
    }

    public function reject(int $rowId, int $userId, string $reason = ''): bool|\WP_Error
    {
        $row = $this->store->find($rowId);
        if ($row === null) {
            return new \WP_Error('not_found', 'No such translation.');
        }
        if ($row->state !== Row::CANDIDATE) {
            return new \WP_Error('invalid_state', sprintf('Only candidates can be rejected; this one is %s.', $row->state));
        }
        $ok = $this->store->update($rowId, [
            'state' => Row::REJECTED,
            'reason' => 'rejected',
            'note' => substr($reason, 0, 2000),
            'approved_by' => $userId,
            'updated_gmt' => $this->clock->gmt(),
        ], Row::CANDIDATE);
        if (!$ok) {
            return new \WP_Error('invalid_state', 'This translation was changed by someone else.');
        }
        do_action('translate_sidecar_rejected', $rowId, $reason);

        return true;
    }

    public function withdraw(int $rowId, int $userId): bool|\WP_Error
    {
        $row = $this->store->find($rowId);
        if ($row === null) {
            return new \WP_Error('not_found', 'No such translation.');
        }
        if ($row->state !== Row::LIVE) {
            return new \WP_Error('invalid_state', sprintf('Only live translations can be withdrawn; this one is %s.', $row->state));
        }
        $post = $this->posts->get($row->postId);
        $extra = $post !== null ? [$this->translations->buildUrl($post, $row->lang)] : [];
        $ok = $this->store->update($rowId, ['state' => Row::WITHDRAWN, 'updated_gmt' => $this->clock->gmt(), 'note' => 'withdrawn by ' . $userId], Row::LIVE);
        if (!$ok) {
            return new \WP_Error('invalid_state', 'This translation was changed by someone else.');
        }
        $this->purger->purge($row->postId, 'withdraw', [$row->lang], $extra);
        do_action('translate_sidecar_withdrawn', $rowId, $row->postId, $row->lang);

        return true;
    }

    /**
     * Restores the newest kept prior version as the live row. The current
     * live row, if any, is set aside as rejected.
     */
    public function rollback(int $postId, string $lang, int $userId): bool|\WP_Error
    {
        $priors = $this->store->rowsFor($postId, $lang, [Row::PRIOR]);
        if ($priors === []) {
            return new \WP_Error('no_prior', 'There is no earlier version to restore.');
        }
        usort($priors, static fn (Row $a, Row $b): int => [(string) $b->updatedGmt, $b->id] <=> [(string) $a->updatedGmt, $a->id]);
        $prior = $priors[0];
        $lock = self::lockName($postId, $lang);
        if (!$this->store->lock($lock, 30)) {
            return new \WP_Error('busy', 'Another change to this translation is in progress. Try again.');
        }
        $now = $this->clock->gmt();
        try {
            $setAside = [];
            foreach ($this->store->rowsFor($postId, $lang, [Row::LIVE]) as $live) {
                if ($this->store->update($live->id, ['state' => Row::REJECTED, 'reason' => 'rolled_back', 'updated_gmt' => $now], Row::LIVE)) {
                    $setAside[] = $live;
                }
            }
            $ok = $this->store->update($prior->id, [
                'state' => Row::LIVE,
                'is_prior' => 0,
                'approved_gmt' => $now,
                'approved_by' => $userId,
                'updated_gmt' => $now,
            ], Row::PRIOR);
            if (!$ok) {
                // The prior moved under us: put the live row back rather
                // than leave the post with no live translation.
                foreach ($setAside as $live) {
                    $this->store->update($live->id, ['state' => Row::LIVE, 'reason' => $live->reason, 'updated_gmt' => $now], Row::REJECTED);
                }
            }
        } finally {
            $this->store->unlock($lock);
        }
        if (!$ok) {
            return new \WP_Error('invalid_state', 'The earlier version was changed by someone else.');
        }
        $this->purger->purge($postId, 'rollback', [$lang]);
        do_action('translate_sidecar_approved', $prior->id, $postId, $lang);

        return true;
    }

    /**
     * Stores a human translation as a candidate, or updates a candidate in
     * place after a human edit. Guards run as warnings (case 53).
     *
     * @param array<string, string> $texts segment id to translated text
     */
    public function saveManual(int $postId, string $lang, array $texts, int $userId, ?int $rowId = null): int|\WP_Error
    {
        $post = $this->posts->get($postId);
        $language = $this->registry->resolve($lang);
        if ($post === null || $language === null) {
            return new \WP_Error('not_found', 'Unknown post or language.');
        }
        $fields = $this->posts->sourceFields($post);
        $segmented = $this->posts->segmenter()->segment($fields);

        $fallback = [];
        $base = $rowId !== null ? $this->store->find($rowId) : $this->translations->live($postId, $lang);
        if ($rowId !== null && ($base === null || $base->state !== Row::CANDIDATE || $base->postId !== $postId || $base->lang !== $lang)) {
            return new \WP_Error('invalid_state', 'Only a candidate of this post and language can be edited.');
        }
        if ($base !== null) {
            foreach ($base->segments as $seg) {
                $fallback[$seg['hash']] = $seg['text'];
            }
        }
        $translations = [];
        foreach ($segmented->segments as $id => $seg) {
            if (isset($texts[$id]) && trim($texts[$id]) !== '') {
                $translations[$id] = (string) $texts[$id];
            } else {
                $translations[$id] = $fallback[$seg->hash] ?? $seg->text;
            }
        }
        $guards = $this->guards->make();
        $report = new GuardReport();
        try {
            $assembled = (new Reassembler())->assemble($segmented, $translations);
        } catch (PlaceholderException $e) {
            return new \WP_Error('placeholder_mismatch', $e->getMessage());
        }
        $thresholds = $this->guards->thresholds($language);
        foreach ($segmented->segments as $id => $seg) {
            $problem = \IndianOpinion\TranslateSidecar\Guard\Guards::placeholderProblem($seg->text, $translations[$id]);
            if ($problem !== null) {
                $report->fail('placeholder_mismatch', '', ['id' => $id, 'problem' => $problem]);
            }
        }
        $sourceHtml = Segmenter::hasBlocks($fields->content) || !function_exists('wpautop') ? $fields->content : wpautop($fields->content);
        try {
            $guards->checkAssembled($segmented, $assembled, $sourceHtml, $language, $thresholds, $report);
        } catch (\Throwable $e) {
            $report->fail('guard_error', '', ['error' => $e->getMessage()]);
        }
        $report = $report->demote();

        $segments = [];
        foreach ($segmented->segments as $id => $seg) {
            $segments[] = ['id' => $id, 'hash' => $seg->hash, 'text' => $assembled->segmentTexts[$id] ?? ''];
        }
        $now = $this->clock->gmt();
        $values = [
            'title' => $assembled->title,
            'excerpt' => $assembled->excerpt,
            'seo_title' => $assembled->seoTitle,
            'meta_description' => $assembled->metaDescription,
            'content' => $assembled->content,
            'alt_text' => Row::encode($assembled->altMap),
            'source_hash' => $fields->hash(),
            'segment_hashes' => Row::encode($segments),
            'guard_report' => Row::encode($report->toArray()),
            'updated_gmt' => $now,
        ];
        $lock = self::lockName($postId, $lang);
        if (!$this->store->lock($lock, 30)) {
            return new \WP_Error('busy', 'Another change to this translation is in progress. Try again.');
        }
        try {
            if ($rowId !== null) {
                $values['note'] = 'edited by ' . $userId;
                $values['created_by'] = $userId;
                if (!$this->store->update($rowId, $values, Row::CANDIDATE)) {
                    return new \WP_Error('invalid_state', 'This candidate was changed by someone else.');
                }
                $id = $rowId;
            } else {
                foreach ($this->store->rowsFor($postId, $lang, [Row::CANDIDATE]) as $old) {
                    $this->store->update($old->id, ['state' => Row::REJECTED, 'reason' => 'replaced', 'updated_gmt' => $now], Row::CANDIDATE);
                }
                $row = new Row();
                $row->postId = $postId;
                $row->lang = $lang;
                $row->state = Row::CANDIDATE;
                $row->adapter = 'manual';
                $row->createdBy = $userId;
                $row->createdGmt = $now;
                $row->title = $assembled->title;
                $row->excerpt = $assembled->excerpt;
                $row->seoTitle = $assembled->seoTitle;
                $row->metaDescription = $assembled->metaDescription;
                $row->content = $assembled->content;
                $row->altText = $assembled->altMap;
                $row->sourceHash = $fields->hash();
                $row->segments = $segments;
                $row->guardReport = $report->toArray();
                $row->updatedGmt = $now;
                $id = $this->store->insert($row);
            }
        } finally {
            $this->store->unlock($lock);
        }
        do_action('translate_sidecar_candidate_ready', $id, $postId, $lang);

        return $id;
    }
}
