<?php

namespace App\Services\Research;

use App\Exceptions\ApiException;
use App\Jobs\ResearchImage;
use App\Models\Image;
use App\Models\ImageResearchRun;
use App\Models\ResearchSettings;
use Illuminate\Support\Facades\DB;

final class ResearchScheduler
{
    public function expire(int $imageId): void
    {
        ImageResearchRun::where('image_id', $imageId)->whereIn('status', ['queued', 'running'])->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => 'failed', 'error_code' => 'RESEARCH_TIMEOUT', 'finished_at' => now()]);
    }

    public function schedule(Image $image, string $trigger, string $question = ''): ?ImageResearchRun
    {
        return DB::transaction(function () use ($image, $trigger, $question) {
            $locked = Image::whereKey($image->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new ApiException('IMAGE_NOT_FOUND', 404, 'Изображение не найдено.');
            }
            $this->expire($image->id);
            $active = ImageResearchRun::where('image_id', $image->id)->whereIn('status', ['queued', 'running'])->first();
            if ($active) {
                return $active;
            }
            $settings = ResearchSettings::current();
            if ($trigger === 'automatic' && (! $settings->object_research_enabled || ImageResearchRun::where('image_id', $image->id)->where('trigger', 'automatic')->exists())) {
                return null;
            }
            if ($locked->status !== 'ready' || ! $locked->preview_key) {
                throw new ApiException('IMAGE_NOT_RESEARCHABLE', 409, 'Дождитесь готовности превью.');
            }
            $run = ImageResearchRun::create(['image_id' => $image->id, 'trigger' => $trigger, 'provider' => $settings->object_research_provider, 'question' => $question, 'status' => 'queued']);
            ResearchImage::dispatch($run->id)->afterCommit();

            return $run;
        });
    }
}
