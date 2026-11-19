<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar;

use IndianOpinion\TranslateSidecar\Job\Requester;
use IndianOpinion\TranslateSidecar\Language\Registry;
use IndianOpinion\TranslateSidecar\Sitemap\Cache;
use IndianOpinion\TranslateSidecar\Storage\Row;
use IndianOpinion\TranslateSidecar\Storage\Store;
use IndianOpinion\TranslateSidecar\Support\Clock;
use IndianOpinion\TranslateSidecar\Support\Log;

/**
 * Reacts to changes of the original post (cases 16, 87 to 91) and runs the
 * daily clean-up.
 */
final class Lifecycle
{
    public function __construct(
        private readonly Store $store,
        private readonly Registry $registry,
        private readonly Settings $settings,
        private readonly Posts $posts,
        private readonly Translations $translations,
        private readonly Requester $requester,
        private readonly Purger $purger,
        private readonly Cache $sitemaps,
        private readonly Clock $clock,
    ) {
    }

    public function register(): void
    {
        add_action('transition_post_status', [$this, 'onTransition'], 20, 3);
        add_action('post_updated', [$this, 'onUpdated'], 20, 3);
        add_action('before_delete_post', [$this, 'onDelete'], 10, 1);
    }

    /**
     * @return list<string>
     */
    private function langsWithRows(int $postId): array
    {
        $langs = [];
        foreach (array_keys($this->registry->routable()) as $prefix) {
            if ($this->store->rowsFor($postId, $prefix, [Row::LIVE, Row::WITHDRAWN]) !== []) {
                $langs[] = $prefix;
            }
        }

        return $langs;
    }

    public function onTransition(mixed $new, mixed $old, mixed $post): void
    {
        try {
            if (!$post instanceof \WP_Post || !$this->posts->isTranslatableType((string) $post->post_type) || $new === $old) {
                return;
            }
            if ($this->store->liveForPost((int) $post->ID) !== []) {
                $this->purger->purge((int) $post->ID, 'status_' . (string) $new, $this->langsWithRows((int) $post->ID));
            }
            if ($new === 'publish' && $old !== 'publish') {
                $this->autoQueue($post, false);
            }
        } catch (\Throwable $e) {
            Log::error('transition_post_status', $e);
        }
    }

    public function onUpdated(mixed $postId, mixed $after, mixed $before): void
    {
        try {
            if (!$after instanceof \WP_Post || !$before instanceof \WP_Post) {
                return;
            }
            if ($after->post_status !== 'publish' || $before->post_status !== 'publish') {
                return;
            }
            if (!$this->posts->isTranslatableType((string) $after->post_type)) {
                return;
            }
            $this->translations->forget((int) $after->ID);
            $live = $this->translations->liveRows((int) $after->ID);
            if ($live === []) {
                return;
            }
            $stale = false;
            foreach ($live as $row) {
                if ($this->translations->isStale($row, $after)) {
                    $stale = true;
                    break;
                }
            }
            if ($stale) {
                $this->purger->purge((int) $after->ID, 'source_changed', array_keys($live));
                $this->autoQueue($after, true);
            }
        } catch (\Throwable $e) {
            Log::error('post_updated', $e);
        }
    }

    /**
     * Queues jobs for languages with auto on. On update, only languages
     * whose live row is stale are queued.
     */
    public function autoQueue(\WP_Post $post, bool $onlyStale): void
    {
        $langs = [];
        foreach ($this->registry->all() as $prefix => $lang) {
            if (!$lang->auto) {
                continue;
            }
            if ($onlyStale) {
                $row = $this->translations->live((int) $post->ID, $prefix);
                if ($row === null || !$this->translations->isStale($row, $post)) {
                    continue;
                }
            }
            $langs[] = $prefix;
        }
        if ($langs !== []) {
            $this->requester->request((int) $post->ID, $langs, ['source' => 'auto', 'replace' => true]);
        }
    }

    public function onDelete(mixed $postId): void
    {
        try {
            $postId = (int) $postId;
            $post = $this->posts->get($postId);
            if ($post === null || !$this->posts->isTranslatableType((string) $post->post_type) || !$this->store->isWritable()) {
                return;
            }
            $langs = $this->langsWithRows($postId);
            $ids = $this->store->deleteForPost($postId);
            if ($ids !== []) {
                $this->store->memoryDeleteForRows($ids);
            }
            $this->translations->forget($postId);
            $this->sitemaps->markDirty($langs);
        } catch (\Throwable $e) {
            Log::error('before_delete_post', $e);
        }
    }

    /**
     * Daily clean-up: failed and rejected rows past retention, unused
     * memory, old counters. Withdrawn rows stay so their URLs keep
     * answering 410.
     */
    public function daily(): void
    {
        if (!$this->store->isWritable()) {
            return;
        }
        $now = $this->clock->now();
        $days = (int) apply_filters('translate_sidecar_retention_days', $this->settings->int('retention_days'));
        $this->store->prune([Row::FAILED, Row::REJECTED], $this->clock->gmt($now - max(1, $days) * 86400));
        $memoryDays = (int) apply_filters('translate_sidecar_memory_days', $this->settings->int('memory_days'));
        $this->store->memoryPrune($this->clock->gmt($now - max(1, $memoryDays) * 86400));
        $this->store->counterPrune($this->clock->localDay($now - 90 * 86400));
    }
}
