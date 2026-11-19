<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Storage;

/**
 * Creates the three tables with dbDelta and verifies them with SHOW
 * COLUMNS. dbDelta can skip a column add without an error, so the schema
 * version is recorded only after verification passes, and every write is
 * refused while a column is missing.
 */
final class Schema
{
    public const VERSION = '1';
    public const VERSION_OPTION = 'translate_sidecar_schema_version';
    public const ERROR_OPTION = 'translate_sidecar_schema_error';
    public const UNIQUE_OPTION = 'translate_sidecar_unique_slot';

    private ?bool $verified = null;

    /**
     * @param \wpdb $wpdb the database object, or anything with its methods
     */
    public function __construct(private readonly object $wpdb)
    {
    }

    /**
     * @return array{rows:string, memory:string, counters:string}
     */
    public function tables(): array
    {
        $p = (string) $this->wpdb->prefix;

        return [
            'rows' => $p . 'translate_sidecar_rows',
            'memory' => $p . 'translate_sidecar_memory',
            'counters' => $p . 'translate_sidecar_counters',
        ];
    }

    /**
     * @return array<string, list<string>> table name to expected columns
     */
    public function expectedColumns(): array
    {
        $t = $this->tables();

        return [
            $t['rows'] => [
                'id', 'post_id', 'lang', 'state', 'title', 'excerpt', 'seo_title', 'meta_description',
                'content', 'alt_text', 'source_hash', 'segment_hashes', 'adapter', 'provider', 'model',
                'guard_report', 'reason', 'note', 'attempts', 'next_attempt_gmt', 'created_gmt',
                'updated_gmt', 'approved_gmt', 'approved_by', 'is_prior', 'created_by',
            ],
            $t['memory'] => ['segment_hash', 'lang', 'text', 'row_id', 'last_used_gmt'],
            $t['counters'] => ['day', 'metric', 'n'],
        ];
    }

    /**
     * CREATE TABLE statements in the format dbDelta expects.
     *
     * @return list<string>
     */
    public function sql(): array
    {
        $t = $this->tables();
        $collate = method_exists($this->wpdb, 'get_charset_collate') ? (string) $this->wpdb->get_charset_collate() : '';

        return [
            "CREATE TABLE {$t['rows']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  lang varchar(16) NOT NULL DEFAULT '',
  state varchar(16) NOT NULL DEFAULT 'queued',
  title text NOT NULL,
  excerpt text NOT NULL,
  seo_title text NOT NULL,
  meta_description text NOT NULL,
  content longtext NOT NULL,
  alt_text longtext NOT NULL,
  source_hash char(64) NOT NULL DEFAULT '',
  segment_hashes longtext NOT NULL,
  adapter varchar(64) NOT NULL DEFAULT '',
  provider varchar(64) NOT NULL DEFAULT '',
  model varchar(128) NOT NULL DEFAULT '',
  guard_report longtext NOT NULL,
  reason varchar(64) NOT NULL DEFAULT '',
  note text NOT NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  next_attempt_gmt datetime DEFAULT NULL,
  created_gmt datetime DEFAULT NULL,
  updated_gmt datetime DEFAULT NULL,
  approved_gmt datetime DEFAULT NULL,
  approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  is_prior tinyint(1) NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY post_lang_state (post_id,lang,state),
  KEY lang_state_approved (lang,state,approved_gmt),
  KEY state_next (state,next_attempt_gmt)
) {$collate};",
            "CREATE TABLE {$t['memory']} (
  segment_hash char(64) NOT NULL,
  lang varchar(16) NOT NULL,
  text longtext NOT NULL,
  row_id bigint(20) unsigned NOT NULL DEFAULT 0,
  last_used_gmt datetime DEFAULT NULL,
  PRIMARY KEY  (segment_hash,lang),
  KEY row_id (row_id),
  KEY last_used (last_used_gmt)
) {$collate};",
            "CREATE TABLE {$t['counters']} (
  day date NOT NULL,
  metric varchar(16) NOT NULL,
  n bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,metric)
) {$collate};",
        ];
    }

    /**
     * Runs dbDelta, adds the uniqueness slot where the database supports
     * it, and verifies. Returns true when the schema is verified.
     */
    public function install(): bool
    {
        if (!function_exists('dbDelta') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        if (!function_exists('dbDelta')) {
            throw new SchemaException('dbDelta is not available.');
        }
        dbDelta($this->sql());
        $this->addSlot();

        return $this->verify() === [];
    }

    /**
     * Adds a generated column that is "live" or "candidate" for those
     * states and NULL otherwise, with a unique key on post, language and
     * slot. NULLs never collide, so other states are unconstrained. When the
     * database refuses, the code lock alone enforces the rule.
     */
    public function addSlot(): bool
    {
        $rows = $this->tables()['rows'];
        $hasColumn = in_array('slot', $this->columns($rows), true);
        if ($hasColumn && $this->hasUniqueSlot()) {
            update_option(self::UNIQUE_OPTION, 1, false);

            return true;
        }
        // The column without its key enforces nothing, so a lone column
        // (a key dropped by hand, or a partial copy of the table) gets its
        // key back. Both forms are one statement: it applies whole or not
        // at all.
        $sql = $hasColumn
            ? "ALTER TABLE {$rows} ADD UNIQUE KEY one_per_slot (post_id,lang,slot)"
            : "ALTER TABLE {$rows} ADD COLUMN slot varchar(16) GENERATED ALWAYS AS "
                . "(CASE WHEN state IN ('live','candidate') THEN state ELSE NULL END) VIRTUAL, "
                . 'ADD UNIQUE KEY one_per_slot (post_id,lang,slot)';
        $suppress = method_exists($this->wpdb, 'suppress_errors') ? $this->wpdb->suppress_errors(true) : false;
        $ok = $this->wpdb->query($sql);
        if (method_exists($this->wpdb, 'suppress_errors')) {
            $this->wpdb->suppress_errors((bool) $suppress);
        }
        $supported = $ok !== false && $this->hasUniqueSlot();
        update_option(self::UNIQUE_OPTION, $supported ? 1 : 0, false);

        return $supported;
    }

    /**
     * True when the unique key on (post_id, lang, slot) exists.
     */
    public function hasUniqueSlot(): bool
    {
        $rows = $this->wpdb->get_results("SHOW INDEX FROM {$this->tables()['rows']} WHERE Key_name = 'one_per_slot'", ARRAY_A);

        return is_array($rows) && $rows !== [];
    }

    /**
     * @return list<string>
     */
    public function columns(string $table): array
    {
        $rows = $this->wpdb->get_results('SHOW COLUMNS FROM ' . $table, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        $names = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            if (isset($r['Field'])) {
                $names[] = (string) $r['Field'];
            }
        }

        return $names;
    }

    /**
     * Compares SHOW COLUMNS with the expected list. Records the schema
     * version only when nothing is missing; otherwise stores the missing
     * columns and returns them.
     *
     * @return array<string, list<string>> table to missing columns
     */
    public function verify(): array
    {
        $missing = [];
        foreach ($this->expectedColumns() as $table => $expected) {
            $have = $this->columns($table);
            $gap = array_values(array_diff($expected, $have));
            if ($gap !== []) {
                $missing[$table] = $gap;
            }
        }
        if ($missing === []) {
            delete_option(self::ERROR_OPTION);
            update_option(self::VERSION_OPTION, self::VERSION, true);
            $this->verified = true;
        } else {
            update_option(self::ERROR_OPTION, $missing, true);
            $this->verified = false;
        }

        return $missing;
    }

    /**
     * True when the recorded version is current and no error is stored.
     */
    public function isVerified(): bool
    {
        if ($this->verified === null) {
            $error = get_option(self::ERROR_OPTION, []);
            $this->verified = get_option(self::VERSION_OPTION, '') === self::VERSION && (!is_array($error) || $error === []);
        }

        return $this->verified;
    }

    public function needsUpgrade(): bool
    {
        return get_option(self::VERSION_OPTION, '') !== self::VERSION;
    }

    /**
     * Human-readable description of a verification failure.
     *
     * @param array<string, list<string>> $missing
     */
    public static function describe(array $missing): string
    {
        $parts = [];
        foreach ($missing as $table => $cols) {
            $parts[] = sprintf('table %s is missing column%s %s', $table, count($cols) > 1 ? 's' : '', implode(', ', $cols));
        }

        return implode('; ', $parts);
    }

    public function drop(): void
    {
        foreach ($this->tables() as $table) {
            $this->wpdb->query('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
