<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Expressões SQL portáveis entre MySQL/MariaDB, PostgreSQL e SQLite.
 */
class SqlDialect
{
    public static function hourExpression(string $column = 'created_at'): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "EXTRACT(HOUR FROM {$column})::int",
            'sqlite' => "CAST(strftime('%H', {$column}) AS INTEGER)",
            default => "HOUR({$column})",
        };
    }

    public static function dateExpression(string $column = 'created_at'): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "({$column})::date",
            'sqlite' => "date({$column})",
            default => "DATE({$column})",
        };
    }

    public static function monthExpression(string $column = 'created_at'): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            'sqlite' => "strftime('%Y-%m', {$column})",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    public static function bucketExpression(string $granularity, string $column = 'created_at'): string
    {
        return match ($granularity) {
            'hour' => self::hourExpression($column),
            'month' => self::monthExpression($column),
            default => self::dateExpression($column),
        };
    }

    /**
     * True quando a chave JSON está ausente, vazia ou literal "null".
     * `$key` deve ser identificador fixo (nunca input do usuário).
     */
    public static function jsonKeyMissingOrEmpty(string $column, string $key): string
    {
        $key = preg_replace('/[^a-zA-Z0-9_]/', '', $key) ?? '';

        return match (DB::connection()->getDriverName()) {
            'pgsql' => "(NULLIF(TRIM(COALESCE({$column}->>'{$key}', '')), '') IS NULL OR ({$column}->>'{$key}') = 'null')",
            'sqlite' => "(NULLIF(TRIM(COALESCE(json_extract({$column}, '$.{$key}'), '')), '') IS NULL OR CAST(json_extract({$column}, '$.{$key}') AS TEXT) = 'null')",
            default => "(NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.{$key}')), '')), '') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.{$key}')) = 'null')",
        };
    }
}
