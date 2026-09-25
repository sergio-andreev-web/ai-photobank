<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Shared formatting for the public API response envelope (contracts/public-api.openapi.yaml,
 * ADR-0002): RFC 3339 UTC without fractional seconds, and {page, per_page, total, last_page}
 * pagination metadata.
 */
final class Api
{
    public static function dateTime(?Carbon $value): ?string
    {
        return $value?->clone()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return array{page: int, per_page: int, total: int, last_page: int}
     */
    public static function paginationMeta(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }
}
