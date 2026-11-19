<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Support;

/**
 * The few wpdb methods the store uses, on mysqli. Integration tests only.
 */
final class MysqliWpdb
{
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public string $last_error = '';
    public int $insert_id = 0;
    public int $rows_affected = 0;

    public function __construct(private readonly \mysqli $db)
    {
        $db->query("CREATE TABLE IF NOT EXISTS wp_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL, option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'yes', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name))");
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function suppress_errors(bool $on = true): bool
    {
        return false;
    }

    public function prepare(string $sql, mixed ...$args): string
    {
        $i = 0;

        return (string) preg_replace_callback('/%[sd]/', function (array $m) use (&$i, $args): string {
            $v = $args[$i++] ?? '';

            return $m[0] === '%d' ? (string) (int) $v : "'" . $this->db->real_escape_string((string) $v) . "'";
        }, $sql);
    }

    public function query(string $sql): int|false
    {
        try {
            $r = $this->db->query($sql);
        } catch (\mysqli_sql_exception $e) {
            $this->last_error = $e->getMessage();

            return false;
        }
        if ($r === false) {
            $this->last_error = $this->db->error;

            return false;
        }
        $this->rows_affected = (int) $this->db->affected_rows;
        $this->insert_id = (int) $this->db->insert_id;

        return $this->rows_affected;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function get_results(string $sql, string $output = 'OBJECT'): ?array
    {
        $r = $this->db->query($sql);
        if (!$r instanceof \mysqli_result) {
            return null;
        }

        return array_values($r->fetch_all(MYSQLI_ASSOC));
    }

    public function get_var(string $sql): mixed
    {
        $rows = $this->get_results($sql);
        if ($rows === null || $rows === []) {
            return null;
        }

        return array_values($rows[0])[0];
    }

    /**
     * @return list<mixed>
     */
    public function get_col(string $sql): array
    {
        return array_map(static fn (array $r): mixed => array_values($r)[0], $this->get_results($sql) ?? []);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int|false
    {
        $cols = implode(',', array_keys($data));
        $vals = implode(',', array_map(fn ($v): string => $v === null ? 'NULL' : "'" . $this->db->real_escape_string((string) $v) . "'", $data));

        return $this->query("INSERT INTO {$table} ({$cols}) VALUES ({$vals})");
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int|false
    {
        $q = fn ($v): string => $v === null ? 'NULL' : "'" . $this->db->real_escape_string((string) $v) . "'";
        $set = implode(',', array_map(static fn ($k, $v): string => "{$k} = " . $q($v), array_keys($data), $data));
        $cond = implode(' AND ', array_map(static fn ($k, $v): string => "{$k} = " . $q($v), array_keys($where), $where));

        return $this->query("UPDATE {$table} SET {$set} WHERE {$cond}");
    }
}
