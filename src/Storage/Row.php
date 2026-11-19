<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Storage;

/**
 * One row of the translations table: a job, a candidate, a live
 * translation, or a kept prior version.
 */
final class Row
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const FAILED = 'failed';
    public const CANDIDATE = 'candidate';
    public const LIVE = 'live';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';
    /** A superseded live version kept for rollback. */
    public const PRIOR = 'prior';

    public const STATES = [
        self::QUEUED, self::RUNNING, self::FAILED, self::CANDIDATE, self::LIVE,
        self::REJECTED, self::WITHDRAWN, self::PRIOR,
    ];

    public int $id = 0;
    public int $postId = 0;
    public string $lang = '';
    public string $state = self::QUEUED;
    public string $title = '';
    public string $excerpt = '';
    public string $seoTitle = '';
    public string $metaDescription = '';
    public string $content = '';
    /** @var array<int, string> attachment id to translated alt */
    public array $altText = [];
    public string $sourceHash = '';
    /** @var list<array{id:string, hash:string, text:string}> */
    public array $segments = [];
    public string $adapter = '';
    public string $provider = '';
    public string $model = '';
    /** @var array<string, mixed> */
    public array $guardReport = [];
    public string $reason = '';
    public string $note = '';
    public int $attempts = 0;
    public ?string $nextAttemptGmt = null;
    public ?string $createdGmt = null;
    public ?string $updatedGmt = null;
    public ?string $approvedGmt = null;
    public int $approvedBy = 0;
    public bool $isPrior = false;
    public int $createdBy = 0;
    /**
     * True when the row was read without its segment list (a narrow
     * listing query). Code that needs the segments reloads the row.
     */
    public bool $partial = false;

    /**
     * @param array<string, mixed>|object $r a database row
     */
    public static function fromDb(array|object $r): self
    {
        $a = is_object($r) ? get_object_vars($r) : $r;
        $row = new self();
        $row->id = (int) ($a['id'] ?? 0);
        $row->postId = (int) ($a['post_id'] ?? 0);
        $row->lang = (string) ($a['lang'] ?? '');
        $row->state = (string) ($a['state'] ?? self::QUEUED);
        $row->title = (string) ($a['title'] ?? '');
        $row->excerpt = (string) ($a['excerpt'] ?? '');
        $row->seoTitle = (string) ($a['seo_title'] ?? '');
        $row->metaDescription = (string) ($a['meta_description'] ?? '');
        $row->content = (string) ($a['content'] ?? '');
        $alt = self::json($a['alt_text'] ?? null);
        $row->altText = [];
        foreach ($alt as $k => $v) {
            $row->altText[(int) $k] = (string) $v;
        }
        $row->sourceHash = (string) ($a['source_hash'] ?? '');
        $segs = [];
        foreach (self::json($a['segment_hashes'] ?? null) as $s) {
            if (is_array($s)) {
                $segs[] = ['id' => (string) ($s['id'] ?? ''), 'hash' => (string) ($s['hash'] ?? ''), 'text' => (string) ($s['text'] ?? '')];
            }
        }
        $row->segments = $segs;
        $row->partial = !array_key_exists('segment_hashes', $a);
        $row->adapter = (string) ($a['adapter'] ?? '');
        $row->provider = (string) ($a['provider'] ?? '');
        $row->model = (string) ($a['model'] ?? '');
        $row->guardReport = self::json($a['guard_report'] ?? null);
        $row->reason = (string) ($a['reason'] ?? '');
        $row->note = (string) ($a['note'] ?? '');
        $row->attempts = (int) ($a['attempts'] ?? 0);
        $row->nextAttemptGmt = self::nullableDate($a['next_attempt_gmt'] ?? null);
        $row->createdGmt = self::nullableDate($a['created_gmt'] ?? null);
        $row->updatedGmt = self::nullableDate($a['updated_gmt'] ?? null);
        $row->approvedGmt = self::nullableDate($a['approved_gmt'] ?? null);
        $row->approvedBy = (int) ($a['approved_by'] ?? 0);
        $row->isPrior = (bool) (int) ($a['is_prior'] ?? 0);
        $row->createdBy = (int) ($a['created_by'] ?? 0);

        return $row;
    }

    /**
     * Column values for an insert or update.
     *
     * @return array<string, mixed>
     */
    public function toDb(): array
    {
        return [
            'post_id' => $this->postId,
            'lang' => $this->lang,
            'state' => $this->state,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'seo_title' => $this->seoTitle,
            'meta_description' => $this->metaDescription,
            'content' => $this->content,
            'alt_text' => self::encode($this->altText),
            'source_hash' => $this->sourceHash,
            'segment_hashes' => self::encode($this->segments),
            'adapter' => $this->adapter,
            'provider' => $this->provider,
            'model' => $this->model,
            'guard_report' => self::encode($this->guardReport),
            'reason' => $this->reason,
            'note' => $this->note,
            'attempts' => $this->attempts,
            'next_attempt_gmt' => $this->nextAttemptGmt,
            'created_gmt' => $this->createdGmt,
            'updated_gmt' => $this->updatedGmt,
            'approved_gmt' => $this->approvedGmt,
            'approved_by' => $this->approvedBy,
            'is_prior' => $this->isPrior ? 1 : 0,
            'created_by' => $this->createdBy,
        ];
    }

    public static function encode(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Could not encode row data as JSON.');
        }

        return $json;
    }

    /**
     * @return array<mixed>
     */
    private static function json(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (!is_string($v) || $v === '') {
            return [];
        }
        $d = json_decode($v, true);

        return is_array($d) ? $d : [];
    }

    private static function nullableDate(mixed $v): ?string
    {
        if ($v === null || $v === '' || $v === '0000-00-00 00:00:00') {
            return null;
        }

        return (string) $v;
    }
}
