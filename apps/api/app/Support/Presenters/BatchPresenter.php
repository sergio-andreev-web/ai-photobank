<?php

namespace App\Support\Presenters;

use App\Models\Batch;
use App\Support\Api;

/**
 * contracts/public-api.openapi.yaml#/components/schemas/Batch.
 *
 * Progress counters (ТЗ §49, ADR-0005) are always computed from the current
 * `images` rows rather than the denormalized upload_batches counter columns,
 * since no queue worker exists yet (this task) to keep those columns in sync
 * — see contracts/state-machines.md for the exact total/processed/failed rules.
 */
final class BatchPresenter
{
    /**
     * @return array{total: int, processed: int, failed: int}
     */
    public static function progress(Batch $batch): array
    {
        $total = $batch->images()->count();
        $processed = $batch->images()->whereIn('status', ['ready', 'failed'])->count();
        $failed = $batch->images()->where('status', 'failed')->count();

        return [
            'total' => $total,
            'processed' => $processed,
            'failed' => $failed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(Batch $batch): array
    {
        return [
            'id' => $batch->id,
            'name' => $batch->name,
            'status' => $batch->status,
            'progress' => self::progress($batch),
            'created_at' => Api::dateTime($batch->created_at),
            'updated_at' => Api::dateTime($batch->updated_at),
            'completed_at' => Api::dateTime($batch->completed_at),
        ];
    }
}
