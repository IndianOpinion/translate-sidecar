<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Rest;

use IndianOpinion\TranslateSidecar\Plugin;
use IndianOpinion\TranslateSidecar\Storage\Row;

/**
 * REST API under translate-sidecar/v1. Write routes need a capability; the
 * REST nonce is checked by core cookie authentication.
 */
final class Controller
{
    public const NS = 'translate-sidecar/v1';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/translations', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'listTranslations'],
                'permission_callback' => '__return_true',
                'args' => ['id' => ['type' => 'integer', 'required' => true]],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'request'],
                'permission_callback' => fn (): bool => Plugin::can('request'),
                'args' => [
                    'id' => ['type' => 'integer', 'required' => true],
                    'langs' => ['type' => 'array', 'items' => ['type' => 'string'], 'required' => true],
                    'replace' => ['type' => 'boolean', 'default' => false],
                    'dry_run' => ['type' => 'boolean', 'default' => false],
                ],
            ],
        ]);
        register_rest_route(self::NS, '/rows/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'row'],
            'permission_callback' => fn (): bool => Plugin::can('review'),
        ]);
        foreach (['approve', 'reject', 'withdraw', 'rollback'] as $verb) {
            register_rest_route(self::NS, '/rows/(?P<id>\d+)/' . $verb, [
                'methods' => 'POST',
                'callback' => fn ($request) => $this->change($verb, $request),
                'permission_callback' => fn (): bool => Plugin::can('review'),
            ]);
        }
    }

    /**
     * Public: routed translations only. Nothing about candidates.
     *
     * @return array<int, array<string, string>>
     */
    public function listTranslations(mixed $request): array
    {
        $id = (int) self::param($request, 'id');
        $out = [];
        foreach ($this->plugin->translations->routedLanguages($id) as $prefix => $lang) {
            $row = $this->plugin->translations->live($id, $prefix);
            $out[] = [
                'language' => $lang->hreflang,
                'prefix' => $prefix,
                'url' => (string) $this->plugin->translations->url($id, $prefix),
                'approved_gmt' => (string) ($row->approvedGmt ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function request(mixed $request): array
    {
        $langs = (array) self::param($request, 'langs');

        return $this->plugin->requester->request((int) self::param($request, 'id'), array_values(array_map('strval', $langs)), [
            'replace' => (bool) self::param($request, 'replace'),
            'dry_run' => (bool) self::param($request, 'dry_run'),
            'source' => 'rest',
            'user_id' => get_current_user_id(),
        ]);
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function row(mixed $request): array|\WP_Error
    {
        $row = $this->plugin->store->find((int) self::param($request, 'id'));
        if ($row === null) {
            return new \WP_Error('not_found', 'No such translation.', ['status' => 404]);
        }

        return [
            'id' => $row->id,
            'post_id' => $row->postId,
            'lang' => $row->lang,
            'state' => $row->state,
            'title' => $row->title,
            'excerpt' => $row->excerpt,
            'content' => $row->content,
            'adapter' => $row->adapter,
            'reason' => $row->reason,
            'guard_report' => $row->guardReport,
            'approved_gmt' => $row->approvedGmt,
        ];
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function change(string $verb, mixed $request): array|\WP_Error
    {
        $id = (int) self::param($request, 'id');
        $user = get_current_user_id();
        $review = $this->plugin->review;
        if ($verb === 'rollback') {
            $row = $this->plugin->store->find($id);
            if ($row === null) {
                return new \WP_Error('not_found', 'No such translation.', ['status' => 404]);
            }
            $result = $review->rollback($row->postId, $row->lang, $user);
        } else {
            $result = match ($verb) {
                'approve' => $review->approve($id, $user, [
                    'with_warnings' => (bool) self::param($request, 'with_warnings'),
                    'reason' => (string) self::param($request, 'reason'),
                ]),
                'reject' => $review->reject($id, $user, (string) self::param($request, 'reason')),
                default => $review->withdraw($id, $user),
            };
        }
        if ($result instanceof \WP_Error) {
            $result->add_data(['status' => 409]);

            return $result;
        }
        $row = $this->plugin->store->find($id);

        return ['ok' => true, 'state' => $row !== null ? $row->state : Row::LIVE];
    }

    private static function param(mixed $request, string $key): mixed
    {
        if (is_object($request) && method_exists($request, 'get_param')) {
            return $request->get_param($key);
        }

        return is_array($request) ? ($request[$key] ?? null) : null;
    }
}
