<?php

namespace App\Services\Processing;

use App\Models\Image;

/**
 * One processing_tasks type (§25, §28). Split in two so slow work never runs
 * inside a DB transaction and results are persisted exactly once per run:
 *
 * - execute(): outside any transaction; may read files, write variant files
 *   (overwriting the same keys, §28) and call AI. Must be safe to repeat.
 * - persist(): inside the transaction that marks the task succeeded, after
 *   the pipeline re-checked it still owns the task. Writes DB rows only.
 */
interface ProcessingStage
{
    /**
     * @throws StageFailure|\Throwable
     */
    public function execute(Image $image): mixed;

    public function persist(Image $image, mixed $outcome): void;
}
