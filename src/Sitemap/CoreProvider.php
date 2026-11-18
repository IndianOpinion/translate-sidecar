<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Sitemap;

/**
 * Core sitemap provider: one subtype per indexable language. Loaded only
 * when WordPress core sitemaps are available.
 */
final class CoreProvider extends \WP_Sitemaps_Provider
{
    public function __construct(private readonly Sitemaps $sitemaps)
    {
        $this->name = 'translations';
        $this->object_type = 'translations';
    }

    /**
     * @return array<string, object>
     */
    public function get_object_subtypes() // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- core API.
    {
        return $this->sitemaps->coreSubtypes();
    }

    /**
     * @param int    $page_num
     * @param string $object_subtype
     * @return list<array<string, string>>
     */
    public function get_url_list($page_num, $object_subtype = '') // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- core API.
    {
        return $this->sitemaps->coreUrlList((int) $page_num, (string) $object_subtype);
    }

    /**
     * @param string $object_subtype
     */
    public function get_max_num_pages($object_subtype = '') // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- core API.
    {
        if (!isset($this->sitemaps->coreSubtypes()[(string) $object_subtype])) {
            return 0;
        }

        return $this->sitemaps->maxPages((string) $object_subtype);
    }
}
