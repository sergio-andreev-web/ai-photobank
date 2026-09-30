<?php

namespace App\Jobs;

use App\Contracts\Storage\PhotoStorageInterface;
use App\Exceptions\ApiException;
use App\Models\Image;
use App\Models\ImageResearchRun;
use App\Models\ResearchSettings;
use App\Services\Research\ResearchClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ResearchImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 210;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId)
    {
        $this->onQueue('images.research');
    }

    public function handle(ResearchClient $client, PhotoStorageInterface $storage): void
    {
        $run = DB::transaction(function () {
            $run = ImageResearchRun::whereKey($this->runId)->lockForUpdate()->first();
            if (! $run || $run->status !== 'queued') {
                return null;
            }
            if (! Image::find($run->image_id) || $run->trigger === 'automatic' && ! ResearchSettings::current()->object_research_enabled) {
                $run->update(['status' => 'cancelled', 'finished_at' => now()]);

                return null;
            }
            $run->update(['status' => 'running', 'started_at' => now()]);

            return $run;
        });
        if (! $run) {
            return;
        }
        try {
            $image = Image::find($run->image_id);
            if (! $image || ! $image->preview_key || ! $storage->exists($image->preview_key)) {
                throw new ApiException('IMAGE_NOT_RESEARCHABLE', 409, 'Превью недоступно.');
            }
            $result = $client->research($storage->get($image->preview_key), $run->provider, $run->question);
            ImageResearchRun::whereKey($run->id)->where('status', 'running')->update(['status' => 'succeeded', 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'finished_at' => now()]);
        } catch (Throwable $e) {
            ImageResearchRun::whereKey($run->id)->where('status', 'running')->update(['status' => 'failed', 'error_code' => $e instanceof ApiException ? $e->errorCode : 'INTERNAL_ERROR', 'finished_at' => now()]);
        }
    }

    public function failed(?Throwable $e): void
    {
        ImageResearchRun::whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'error_code' => 'RESEARCH_TIMEOUT', 'finished_at' => now()]);
    }
}
