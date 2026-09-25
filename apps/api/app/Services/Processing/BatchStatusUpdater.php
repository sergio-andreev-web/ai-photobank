<?php

namespace App\Services\Processing;

use App\Models\Batch;
use App\Support\Presenters\BatchPresenter;
use Illuminate\Support\Facades\DB;

/**
 * upload_batches transitions after `complete` (contracts/state-machines.md,
 * Batch; ADR-0005). Every call locks the batch row and recounts from images,
 * so concurrent image finalizations and `complete` serialize on the batch and
 * the last one to commit always sees the final picture.
 */
final class BatchStatusUpdater
{
    public const array FINAL_STATUSES = ['completed', 'completed_with_errors', 'failed'];

    private const array OPEN_STATUSES = ['created', 'uploading'];

    /**
     * POST /batches/{id}/complete (§26): idempotent, returns the current state.
     */
    public function complete(int $batchId): Batch
    {
        return DB::transaction(function () use ($batchId): Batch {
            $batch = Batch::lockForUpdate()->findOrFail($batchId);

            if (in_array($batch->status, self::OPEN_STATUSES, true)) {
                $progress = BatchPresenter::progress($batch);

                if ($progress['processed'] === $progress['total']) {
                    $this->finish($batch, $progress);
                } else {
                    $batch->status = 'processing';
                }

                $this->storeCounters($batch, $progress);
                $batch->save();
            }

            return $batch;
        });
    }

    /**
     * After an image reached ready/failed, or left a final status via
     * retry/reprocess, or was soft-deleted. Batches still open for uploads
     * keep their status: only `complete` closes them.
     */
    public function refresh(int $batchId): void
    {
        DB::transaction(function () use ($batchId): void {
            $batch = Batch::lockForUpdate()->find($batchId);
            if ($batch === null) {
                return;
            }

            $progress = BatchPresenter::progress($batch);
            $allFinal = $progress['processed'] === $progress['total'];

            if ($batch->status === 'processing' && $allFinal) {
                $this->finish($batch, $progress);
            } elseif (in_array($batch->status, self::FINAL_STATUSES, true) && ! $allFinal) {
                // completed_at is refreshed when the batch finishes again.
                $batch->status = 'processing';
            }

            $this->storeCounters($batch, $progress);
            $batch->save();
        });
    }

    /**
     * @param  array{total: int, processed: int, failed: int}  $progress
     */
    private function finish(Batch $batch, array $progress): void
    {
        $batch->status = match (true) {
            $progress['total'] > 0 && $progress['failed'] === $progress['total'] => 'failed',
            $progress['failed'] > 0 => 'completed_with_errors',
            default => 'completed',
        };
        $batch->completed_at = now();
    }

    /**
     * Denormalized §14 columns; the API itself always recounts (BatchPresenter).
     *
     * @param  array{total: int, processed: int, failed: int}  $progress
     */
    private function storeCounters(Batch $batch, array $progress): void
    {
        $batch->total_images = $progress['total'];
        $batch->processed_images = $progress['processed'];
        $batch->failed_images = $progress['failed'];
    }
}
