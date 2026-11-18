<?php

/**
 * Minimal WordPress classes for the test stub layer.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName

final class WP_Post
{
    public int $ID = 0;
    public int $post_author = 1;
    public string $post_title = '';
    public string $post_content = '';
    public string $post_excerpt = '';
    public string $post_status = 'publish';
    public string $post_type = 'post';
    public string $post_name = '';
    public string $post_password = '';
    public int $post_parent = 0;
    public string $post_date_gmt = '2026-01-01 00:00:00';
    public string $post_modified_gmt = '2026-01-01 00:00:00';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        foreach ($data as $k => $v) {
            $this->{$k} = $v;
        }
    }
}

class WP_Error
{
    /** @var array<string, list<string>> */
    public array $errors = [];
    /** @var array<string, mixed> */
    public array $error_data = [];

    public function __construct(string $code = '', string $message = '', mixed $data = '')
    {
        if ($code !== '') {
            $this->errors[$code][] = $message;
            if ($data !== '') {
                $this->error_data[$code] = $data;
            }
        }
    }

    public function get_error_code(): string
    {
        return (string) (array_key_first($this->errors) ?? '');
    }

    public function get_error_message(): string
    {
        $code = $this->get_error_code();

        return $this->errors[$code][0] ?? '';
    }

    public function add_data(mixed $data): void
    {
        $this->error_data[$this->get_error_code()] = $data;
    }
}

class WP_Sitemaps_Provider
{
    protected string $name = '';
    protected string $object_type = '';
}

final class WP_Query
{
    public bool $is_404 = false;

    public function set_404(): void
    {
        $this->is_404 = true;
    }
}

final class WP_Rewrite_Stub
{
    /** @var array<string, string> */
    public array $rules = [];

    /**
     * @return array<string, string>
     */
    public function wp_rewrite_rules(): array
    {
        return $this->rules;
    }
}

/**
 * A wpdb stand-in that records queries and answers SHOW COLUMNS from a
 * configurable map. Used by the schema and uninstall tests.
 */
final class FakeWpdb
{
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public string $postmeta = 'wp_postmeta';
    public string $last_error = '';
    /** @var list<string> */
    public array $queries = [];
    /** @var array<string, list<string>> table to columns */
    public array $columns = [];
    public bool $refuseAlter = false;

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }

    public function suppress_errors(bool $on = true): bool
    {
        return false;
    }

    public function query(string $sql): int|bool
    {
        $this->queries[] = $sql;
        if (str_starts_with($sql, 'ALTER TABLE') && $this->refuseAlter) {
            return false;
        }
        if (preg_match('/^ALTER TABLE (\S+) ADD COLUMN (\w+)/', $sql, $m) === 1) {
            $this->columns[$m[1]][] = $m[2];
        }
        if (preg_match('/^DROP TABLE IF EXISTS (\S+)/', $sql, $m) === 1) {
            unset($this->columns[$m[1]]);
        }

        return 1;
    }

    /**
     * @return list<array<string, string>>
     */
    public function get_results(string $sql, string $output = 'OBJECT'): array
    {
        $this->queries[] = $sql;
        if (preg_match('/^SHOW COLUMNS FROM (\S+)/', $sql, $m) === 1) {
            return array_map(static fn (string $c): array => ['Field' => $c], $this->columns[$m[1]] ?? []);
        }

        return [];
    }

    public function prepare(string $sql, mixed ...$args): string
    {
        foreach ($args as $a) {
            $sql = (string) preg_replace('/%[sd]/', is_int($a) ? (string) $a : "'" . addslashes((string) $a) . "'", $sql, 1);
        }

        return $sql;
    }

    public function esc_like(string $s): string
    {
        return addcslashes($s, '_%\\');
    }
}
