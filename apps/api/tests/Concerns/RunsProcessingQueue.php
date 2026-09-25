<?php

namespace Tests\Concerns;

use App\Contracts\Ai\AiClientInterface;
use App\Contracts\Tagging\TagResolverInterface;
use App\Jobs\RunProcessingTask;
use App\Models\Batch;
use App\Models\Image;
use App\Models\User;
use App\Services\Ai\FakeAiClient;
use App\Services\Processing\ProcessingPipeline;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StubTagResolver;

/**
 * Deterministic worker for pipeline tests: Queue::fake() records every
 * RunProcessingTask message (queue name and delay included) and
 * runQueuedJobs() executes them in push order, like a single worker that
 * ignores delays. Each message runs at most once unless re-run explicitly,
 * which is how duplicate/redelivered messages are simulated.
 */
trait RunsProcessingQueue
{
    protected FakeAiClient $ai;

    protected StubTagResolver $resolver;

    private int $nextJobIndex = 0;

    protected function setUpRunsProcessingQueue(): void
    {
        Queue::fake();
        Storage::fake('photobank');

        $this->ai = new FakeAiClient;
        $this->app->instance(AiClientInterface::class, $this->ai);

        $this->resolver = new StubTagResolver;
        $this->app->instance(TagResolverInterface::class, $this->resolver);

        $this->nextJobIndex = 0;
    }

    /**
     * @return list<RunProcessingTask>
     */
    protected function pushedJobs(): array
    {
        return Queue::pushed(RunProcessingTask::class)->values()->all();
    }

    /**
     * Runs every not-yet-run message, including those pushed while running.
     */
    protected function runQueuedJobs(int $limit = 100): int
    {
        $ran = 0;

        while ($ran < $limit && isset($this->pushedJobs()[$this->nextJobIndex])) {
            $this->runJob($this->pushedJobs()[$this->nextJobIndex++]);
            $ran++;
        }

        return $ran;
    }

    protected function runJob(RunProcessingTask $job, bool $redelivered = false): void
    {
        app(ProcessingPipeline::class)->runTask($job->taskId, $redelivered);
    }

    /**
     * An image stored like upload does (original on the photobank disk), with
     * its first task queued through the pipeline.
     */
    protected function createUploadedImage(?Batch $batch = null, ?string $contents = null): Image
    {
        $user = User::first() ?? User::create([
            'name' => 'Admin',
            'email' => 'pipeline@example.test',
            'password' => 'secret-password',
            'role' => 'admin',
        ]);

        $batch ??= Batch::create(['status' => 'processing', 'created_by' => $user->id]);
        $contents ??= $this->jpeg(64, 48, random_int(0, 0xFFFFFF));
        $key = 'originals/2026/09/'.$batch->id.'/'.bin2hex(random_bytes(8)).'.jpg';
        Storage::disk('photobank')->put($key, $contents);

        $image = Image::create([
            'batch_id' => $batch->id,
            'original_name' => 'photo.jpg',
            'storage_key' => $key,
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'file_size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'status' => 'uploaded',
            'created_by' => $user->id,
        ]);

        app(ProcessingPipeline::class)->start($image);

        return $image;
    }

    protected function processedImage(?Batch $batch = null): Image
    {
        $image = $this->createUploadedImage($batch);
        $this->runQueuedJobs();

        return $image->refresh();
    }

    protected function jpeg(int $width, int $height, int $color = 0x3366CC): string
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefill($gd, 0, 0, $color);
        ob_start();
        imagejpeg($gd, null, 90);

        return (string) ob_get_clean();
    }
}
