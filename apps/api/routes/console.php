<?php

use App\Jobs\RegenerateTagEmbedding;
use App\Models\Image;
use App\Models\Tag;
use App\Models\User;
use App\Services\Processing\ProcessingPipeline;
use App\Services\Processing\ProcessingState;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('photobank:create-admin {email} {--name=} {--update-password}', function (): int {
    $email = $this->argument('email');
    $name = $this->option('name');
    $validator = Validator::make(['email' => $email, 'name' => $name], [
        'email' => ['required', 'email:rfc', 'max:255'],
        'name' => ['nullable', 'string', 'max:255'],
    ]);
    if ($validator->fails()) {
        $this->error($validator->errors()->first());

        return 1;
    }

    $user = User::query()->where('email', $email)->first();
    if ($user && ! $this->option('update-password')) {
        $this->error('A user with this email already exists. Use --update-password to change its password.');

        return 1;
    }
    if (! $user && $this->option('update-password')) {
        $this->error('No user with this email exists. Omit --update-password to create one.');

        return 1;
    }
    if ($user && $user->role !== 'admin') {
        $this->error('The existing user is not an administrator.');

        return 1;
    }

    $password = getenv('PHOTOBANK_ADMIN_PASSWORD');
    if ($password === false) {
        $password = $this->secret('Admin password');
    }
    $passwordValidator = Validator::make(['password' => $password], [
        'password' => ['required', 'string', 'min:8'],
    ]);
    if ($passwordValidator->fails()) {
        $this->error('Admin password must be at least 8 characters long.');

        return 1;
    }

    if ($user) {
        $user->update(['password' => Hash::make($password)]);
        $this->info('Administrator password updated.');
    } else {
        User::query()->create([
            'name' => $name ?: $email,
            'email' => $email,
            'role' => 'admin',
            'password' => Hash::make($password),
        ]);
        $this->info('Administrator created.');
    }

    return 0;
})->purpose('Create an administrator or update an existing administrator password');

Artisan::command('photobank:reindex-tags {--dry-run} {--limit=0} {--batch=100}', function (): int {
    $limit = $this->option('limit');
    $batch = $this->option('batch');
    if (! ctype_digit((string) $limit) || ! ctype_digit((string) $batch) || (int) $batch < 1) {
        $this->error('--limit must be a non-negative integer and --batch must be a positive integer.');

        return 1;
    }

    $modelKey = config('ai.embedding.active_model_key');
    $modelVersion = config('ai.embedding.active_model_version');
    if (! is_string($modelKey) || $modelKey === '' || ! is_string($modelVersion) || $modelVersion === '') {
        $this->error('EMBEDDING_ACTIVE_MODEL_KEY and EMBEDDING_ACTIVE_MODEL_VERSION must be configured.');

        return 1;
    }

    $query = Tag::query()->whereDoesntHave('embeddings', fn ($q) => $q
        ->where('model_key', $modelKey)->where('model_version', $modelVersion));
    $selected = 0;
    $dryRun = (bool) $this->option('dry-run');
    $query->chunkById((int) $batch, function ($tags) use (&$selected, $limit, $dryRun) {
        foreach ($tags as $tag) {
            if (! $dryRun) {
                RegenerateTagEmbedding::dispatch($tag->id);
            }
            $selected++;
            if ((int) $limit > 0 && $selected >= (int) $limit) {
                return false;
            }
        }
    });

    $this->info(sprintf('Tags: %d eligible; %s (model %s / %s).', $selected,
        $dryRun ? 'dry run, none queued' : 'regeneration dispatch requested', $modelKey, $modelVersion));

    return 0;
})->purpose('Queue missing tag embeddings for the active model version');

Artisan::command('photobank:reindex-images {--dry-run} {--limit=0} {--batch=100}', function (ProcessingPipeline $pipeline): int {
    $limit = $this->option('limit');
    $batch = $this->option('batch');
    if (! ctype_digit((string) $limit) || ! ctype_digit((string) $batch) || (int) $batch < 1) {
        $this->error('--limit must be a non-negative integer and --batch must be a positive integer.');

        return 1;
    }

    $modelKey = config('ai.embedding.active_model_key');
    $modelVersion = config('ai.embedding.active_model_version');
    if (! is_string($modelKey) || $modelKey === '' || ! is_string($modelVersion) || $modelVersion === '') {
        $this->error('EMBEDDING_ACTIVE_MODEL_KEY and EMBEDDING_ACTIVE_MODEL_VERSION must be configured.');

        return 1;
    }

    $query = Image::query()->where('status', ProcessingState::IMAGE_READY)
        ->whereDoesntHave('embeddings', fn ($q) => $q->where('active', true)
            ->where('model_key', $modelKey)->where('model_version', $modelVersion));
    $selected = 0;
    $dryRun = (bool) $this->option('dry-run');
    $query->chunkById((int) $batch, function ($images) use (&$selected, $limit, $dryRun, $pipeline) {
        foreach ($images as $image) {
            if (! $dryRun) {
                $pipeline->reprocess($image->id);
            }
            $selected++;
            if ((int) $limit > 0 && $selected >= (int) $limit) {
                return false;
            }
        }
    });

    $this->info(sprintf('Images: %d eligible; %s (model %s / %s).', $selected,
        $dryRun ? 'dry run, none queued' : 'reprocess requested', $modelKey, $modelVersion));

    return 0;
})->purpose('Queue ready images missing an active embedding for the active model version');
