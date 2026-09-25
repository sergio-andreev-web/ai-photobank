<?php

namespace App\Services\Processing;

/**
 * Status/type/error-code values used by the pipeline, copied from
 * contracts/schemas/enums.json (ImageStatus, ProcessingTaskType,
 * ProcessingTaskStatus) and the processing error-code catalog in
 * contracts/README.md. Not a new enum — just named constants for the
 * contract values so the state machine (contracts/state-machines.md) reads
 * without string literals.
 */
final class ProcessingState
{
    // images.status (§15)
    public const string IMAGE_UPLOADED = 'uploaded';

    public const string IMAGE_QUEUED = 'queued';

    public const string IMAGE_PROCESSING = 'processing';

    public const string IMAGE_READY = 'ready';

    public const string IMAGE_FAILED = 'failed';

    // processing_tasks.type (§25), in execution order (§28)
    public const string TASK_METADATA = 'metadata';

    public const string TASK_VARIANTS = 'variants';

    public const string TASK_AI_ANALYSIS = 'ai_analysis';

    public const string TASK_TAG_RESOLUTION = 'tag_resolution';

    public const string TASK_FINALIZE = 'finalize';

    public const array TASK_ORDER = [
        self::TASK_METADATA,
        self::TASK_VARIANTS,
        self::TASK_AI_ANALYSIS,
        self::TASK_TAG_RESOLUTION,
        self::TASK_FINALIZE,
    ];

    /** Tasks during which the image is `processing` (state-machines.md, Image). */
    public const array AI_PHASE_TASKS = [
        self::TASK_AI_ANALYSIS,
        self::TASK_TAG_RESOLUTION,
        self::TASK_FINALIZE,
    ];

    // processing_tasks.status (§25)
    public const string TASK_QUEUED = 'queued';

    public const string TASK_PROCESSING = 'processing';

    public const string TASK_SUCCEEDED = 'succeeded';

    public const string TASK_FAILED = 'failed';

    // images.error_code / processing_tasks.error_code (contracts/README.md)
    public const string ERROR_METADATA_EXTRACTION_FAILED = 'METADATA_EXTRACTION_FAILED';

    public const string ERROR_VARIANT_GENERATION_FAILED = 'VARIANT_GENERATION_FAILED';

    public const string ERROR_AI_SERVICE_UNAVAILABLE = 'AI_SERVICE_UNAVAILABLE';

    public const string ERROR_AI_REQUEST_REJECTED = 'AI_REQUEST_REJECTED';

    public const string ERROR_AI_RESPONSE_INVALID = 'AI_RESPONSE_INVALID';

    public const string ERROR_AI_OUTPUT_INVALID = 'AI_OUTPUT_INVALID';

    public const string ERROR_TAG_RESOLUTION_FAILED = 'TAG_RESOLUTION_FAILED';

    public const string ERROR_INTERNAL = 'INTERNAL_ERROR';

    public static function nextTask(string $type): ?string
    {
        $index = array_search($type, self::TASK_ORDER, true);

        return self::TASK_ORDER[$index + 1] ?? null;
    }
}
