<?php

/**
 * State behind the WordPress function stubs.
 */

declare(strict_types=1);

final class WpStub
{
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, mixed> */
    public static array $siteOptions = [];
    /** @var array<string, array<int, list<array{0:callable, 1:int}>>> */
    public static array $hooks = [];
    /** @var array<string, int> */
    public static array $actionCounts = [];
    /** @var array<string, list<list<mixed>>> */
    public static array $fired = [];
    /** @var array<int, WP_Post> */
    public static array $posts = [];
    /** @var array<int, array<string, list<mixed>>> */
    public static array $meta = [];
    /** @var array<string, mixed> */
    public static array $transients = [];
    public static int $currentUser = 0;
    /** @var array<int, list<string>> user id to capabilities */
    public static array $caps = [];
    public static bool $multisite = false;
    public static int $blogId = 1;
    /** @var array<int, array{options: array<string, mixed>}> */
    public static array $blogs = [];
    public static bool $admin = false;
    public static bool $trailingSlash = true;
    public static int $currentPostId = 0;
    public static int $queriedId = 0;
    public static bool $singular = false;
    /** @var array<string, int> */
    public static array $scheduled = [];
    /** @var list<array{0:string, 1:string, 2:string}> */
    public static array $rewriteRules = [];
    public static int $flushes = 0;
    /** @var list<array{0:string, 1:int}> */
    public static array $redirects = [];
    /** @var list<int> */
    public static array $statuses = [];
    /** @var list<int> */
    public static array $cleaned = [];
    /** @var array<string, mixed> */
    public static array $queryVars = [];
    public static string $timezone = 'UTC';
    public static string $locale = 'en_US';
    public static bool $sitemapsEnabled = true;
    public static int $getPostsCalls = 0;
    /** @var list<array<string, mixed>> */
    public static array $blocks = [];
    /** @var array<string, array<string, int>> taxonomy to slug to term id */
    public static array $terms = [];

    public static function reset(): void
    {
        self::$options = ['permalink_structure' => '/%postname%/', 'blogname' => 'Example'];
        self::$siteOptions = [];
        self::$hooks = [];
        self::$actionCounts = [];
        self::$fired = [];
        self::$posts = [];
        self::$meta = [];
        self::$transients = [];
        self::$currentUser = 0;
        self::$caps = [];
        self::$multisite = false;
        self::$blogId = 1;
        self::$blogs = [];
        self::$admin = false;
        self::$trailingSlash = true;
        self::$currentPostId = 0;
        self::$queriedId = 0;
        self::$singular = false;
        self::$scheduled = [];
        self::$rewriteRules = [];
        self::$flushes = 0;
        self::$redirects = [];
        self::$statuses = [];
        self::$cleaned = [];
        self::$queryVars = [];
        self::$timezone = 'UTC';
        self::$locale = 'en_US';
        self::$sitemapsEnabled = true;
        self::$getPostsCalls = 0;
        self::$blocks = [];
        self::$terms = [];
        $GLOBALS['wp_rewrite'] = new WP_Rewrite_Stub();
        $GLOBALS['wp_filter'] = [];
        $GLOBALS['shortcode_tags'] = [];
        $GLOBALS['wp_query'] = new WP_Query();
    }

    public static function addPost(array $data): WP_Post
    {
        $post = new WP_Post($data);
        if ($post->post_name === '') {
            $post->post_name = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $post->post_title));
        }
        self::$posts[$post->ID] = $post;

        return $post;
    }

    public static function fired(string $hook): array
    {
        return self::$fired[$hook] ?? [];
    }
}
