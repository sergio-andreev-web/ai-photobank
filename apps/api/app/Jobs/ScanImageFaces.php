<?php

namespace App\Jobs;

use App\Models\Image;
use App\Services\Faces\FaceScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Faces of one image (ADR-0015), after the image became ready. Its own job, not a
 * processing task: retries do not touch the image status and a final failure only logs.
 */
class ScanImageFaces implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $imageId)
    {
        $this->onQueue(config('photobank.processing.queues.ai'));
    }

    public function handle(FaceScanner $scanner): void
    {
        $image = Image::withTrashed()->find($this->imageId);
        if ($image !== null) {
            $scanner->scan($image);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::warning('faces.scan.failed', ['image_id' => $this->imageId, 'error' => $e->getMessage()]);
    }
}
