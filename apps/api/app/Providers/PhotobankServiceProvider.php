<?php

namespace App\Providers;

use App\Contracts\Ai\AiClientInterface;
use App\Contracts\Storage\PhotoStorageInterface;
use App\Services\Ai\FakeAiClient;
use App\Services\Ai\HttpAiClient;
use App\Services\Storage\LocalPhotoStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Binds the module-level interfaces from ТЗ §7 and §11: AiClientInterface
 * (config('ai.driver')) and PhotoStorageInterface (the "photobank" disk).
 */
class PhotobankServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiClientInterface::class, function (): AiClientInterface {
            return match ($driver = config('ai.driver')) {
                'fake' => new FakeAiClient,
                'http' => new HttpAiClient(
                    baseUrl: config('ai.base_url'),
                    apiKey: config('ai.api_key'),
                    timeoutSeconds: config('ai.timeout'),
                ),
                default => throw new InvalidArgumentException("Unknown ai.driver [{$driver}]."),
            };
        });

        $this->app->singleton(PhotoStorageInterface::class, fn (): PhotoStorageInterface => new LocalPhotoStorage(
            Storage::disk('photobank'),
        ));
    }
}
