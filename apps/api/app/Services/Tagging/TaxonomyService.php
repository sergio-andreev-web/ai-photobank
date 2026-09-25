<?php

namespace App\Services\Tagging;

use App\Exceptions\ApiException;
use App\Jobs\RegenerateTagEmbedding;
use App\Models\ImageTag;
use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\TagCandidate;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Canonical taxonomy changes — only ever by an admin action (ТЗ §2, §16,
 * §17, §50; ADR-0007):
 *
 * - tags are unique by normalized_name + type (§16); the same name under
 *   different types is allowed;
 * - a normalized alias is unique globally and never equals the
 *   normalized_name of another tag; a tag's normalized_name never equals an
 *   alias of another tag (collision rule A) — so stage 2 yields ≤ 1 tag;
 * - parent: not the tag itself, not a descendant, not archived;
 * - archived tags keep their links but accept no new assignments;
 * - name/alias changes re-embed the tag asynchronously (§21).
 *
 * Collision checks span two tables, so they run under a transaction-scoped
 * advisory lock on the normalized name (PostgreSQL). The DB unique indexes
 * remain the last line of defence and are mapped to the same 409s.
 */
final class TaxonomyService
{
    private const int SLUG_ATTEMPTS = 5;

    /**
     * @throws ApiException TAG_ALREADY_EXISTS, TAG_PARENT_INVALID
     */
    public function createTag(string $name, string $type, ?int $parentId, ?int $createdBy): Tag
    {
        $name = trim($name);
        $normalized = TagNormalizer::normalize($name);

        $tag = $this->withSlugRetry(function (int $attempt) use ($name, $normalized, $type, $parentId, $createdBy): Tag {
            return DB::transaction(function () use ($attempt, $name, $normalized, $type, $parentId, $createdBy): Tag {
                $this->lockNames([$normalized]);
                $this->assertTagNameFree($normalized, $type, null);

                if ($parentId !== null) {
                    $this->assertValidParent(null, $parentId);
                }

                return Tag::create([
                    'parent_id' => $parentId,
                    'name' => $name,
                    'slug' => $this->uniqueSlug($name, null, $attempt),
                    'normalized_name' => $normalized,
                    'type' => $type,
                    'status' => Taxonomy::TAG_ACTIVE,
                    'created_by' => $createdBy,
                ]);
            });
        }, fn () => $this->assertTagNameFree($normalized, $type, null));

        RegenerateTagEmbedding::dispatch($tag->id);

        return $tag;
    }

    /**
     * @param  array{name?: string, type?: string, parent_id?: int|null, status?: string}  $changes
     *
     * @throws ApiException TAG_ALREADY_EXISTS, TAG_PARENT_INVALID
     */
    public function updateTag(Tag $tag, array $changes): Tag
    {
        $renamed = false;

        $this->withSlugRetry(function (int $attempt) use ($tag, $changes, &$renamed): Tag {
            return DB::transaction(function () use ($attempt, $tag, $changes, &$renamed): Tag {
                /** @var Tag $locked */
                $locked = Tag::lockForUpdate()->findOrFail($tag->id);

                $name = array_key_exists('name', $changes) ? trim($changes['name']) : $locked->name;
                $type = $changes['type'] ?? $locked->type;
                $normalized = TagNormalizer::normalize($name);

                if ($normalized !== $locked->normalized_name || $type !== $locked->type) {
                    $this->lockNames([$normalized]);
                    $this->assertTagNameFree($normalized, $type, $locked->id);
                }

                if (array_key_exists('parent_id', $changes) && $changes['parent_id'] !== null
                    && $changes['parent_id'] !== $locked->parent_id) {
                    $this->assertValidParent($locked->id, $changes['parent_id']);
                }

                if ($name !== $locked->name) {
                    $renamed = true;
                    $locked->slug = $this->uniqueSlug($name, $locked->id, $attempt);
                }

                $locked->name = $name;
                $locked->normalized_name = $normalized;
                $locked->type = $type;

                if (array_key_exists('parent_id', $changes)) {
                    $locked->parent_id = $changes['parent_id'];
                }

                if (isset($changes['status'])) {
                    $locked->status = $changes['status'];
                }

                $locked->save();

                return $locked;
            });
        }, function () use ($tag, $changes): void {
            $name = array_key_exists('name', $changes) ? trim($changes['name']) : $tag->name;
            $this->assertTagNameFree(TagNormalizer::normalize($name), $changes['type'] ?? $tag->type, $tag->id);
        });

        if ($renamed) {
            RegenerateTagEmbedding::dispatch($tag->id);
        }

        return $tag->refresh();
    }

    /**
     * DELETE /tags/{id} (§50, ADR-0007): archived when in use (image_tags,
     * child tags or a tag_candidates.closest_tag_id reference), otherwise
     * removed. Returns the archived tag, or null when it was deleted.
     */
    public function deleteTag(Tag $tag): ?Tag
    {
        try {
            $deleted = DB::transaction(function () use ($tag): bool {
                $locked = Tag::lockForUpdate()->findOrFail($tag->id);

                if ($this->isInUse($locked)) {
                    return false;
                }

                $locked->delete();

                return true;
            });
        } catch (QueryException) {
            // A link/child/candidate reference appeared after the check; the FK
            // (restrict) refused the delete, so the tag is in use after all.
            $deleted = false;
        }

        if ($deleted) {
            return null;
        }

        return $this->updateTag($tag, ['status' => Taxonomy::TAG_ARCHIVED]);
    }

    /**
     * @throws ApiException ALIAS_ALREADY_EXISTS
     */
    public function addAlias(Tag $tag, string $alias, ?string $locale): TagAlias
    {
        $created = $this->createAlias($tag, trim($alias), $locale);

        RegenerateTagEmbedding::dispatch($tag->id);

        return $created;
    }

    /**
     * For candidate merge (ADR-0008): adds $alias to $tag unless it already
     * resolves to $tag (its own name or an existing alias of it). Must run
     * inside the caller's transaction; the caller re-embeds the tag.
     *
     * @return bool Whether an alias row was created.
     *
     * @throws ApiException ALIAS_ALREADY_EXISTS when it resolves to another tag.
     */
    public function ensureAlias(Tag $tag, string $alias): bool
    {
        $normalized = TagNormalizer::normalize($alias);
        $this->lockNames([$normalized]);

        $existing = TagAlias::where('normalized_alias', $normalized)->first();
        if ($existing !== null && $existing->tag_id === $tag->id) {
            return false;
        }

        if ($existing === null && $normalized === $tag->normalized_name
            && ! Tag::where('normalized_name', $normalized)->whereKeyNot($tag->id)->exists()) {
            return false;
        }

        $this->createAlias($tag, trim($alias), null);

        return true;
    }

    /**
     * @throws ApiException ALIAS_NOT_FOUND
     */
    public function removeAlias(Tag $tag, int $aliasId): void
    {
        $deleted = TagAlias::whereKey($aliasId)->where('tag_id', $tag->id)->delete();

        if ($deleted === 0) {
            throw new ApiException('ALIAS_NOT_FOUND', 404, 'Alias not found');
        }

        RegenerateTagEmbedding::dispatch($tag->id);
    }

    public function isInUse(Tag $tag): bool
    {
        return ImageTag::where('tag_id', $tag->id)->exists()
            || Tag::where('parent_id', $tag->id)->exists()
            || TagCandidate::where('closest_tag_id', $tag->id)->exists();
    }

    /**
     * @throws ApiException TAG_ALREADY_EXISTS
     */
    public function assertTagNameFree(string $normalized, string $type, ?int $exceptTagId): void
    {
        $sameName = Tag::where('normalized_name', $normalized)->where('type', $type)
            ->when($exceptTagId !== null, fn ($q) => $q->whereKeyNot($exceptTagId))
            ->value('id');

        if ($sameName !== null) {
            throw $this->tagExists((int) $sameName);
        }

        $aliasOwner = TagAlias::where('normalized_alias', $normalized)
            ->when($exceptTagId !== null, fn ($q) => $q->where('tag_id', '!=', $exceptTagId))
            ->value('tag_id');

        if ($aliasOwner !== null) {
            throw $this->tagExists((int) $aliasOwner);
        }
    }

    /**
     * @throws ApiException TAG_PARENT_INVALID
     */
    public function assertValidParent(?int $tagId, int $parentId): void
    {
        $parent = Tag::find($parentId);

        if ($parent === null) {
            throw $this->parentInvalid('Parent tag does not exist');
        }

        if ($parent->status !== Taxonomy::TAG_ACTIVE) {
            throw $this->parentInvalid('Parent tag is archived');
        }

        if ($tagId === null) {
            return;
        }

        // Walk up from the proposed parent: meeting the tag itself means a cycle.
        $seen = [];
        for ($current = $parent; $current !== null; $current = $current->parent_id ? Tag::find($current->parent_id) : null) {
            if ($current->id === $tagId) {
                throw $this->parentInvalid('A tag cannot be its own parent or a child of its descendant');
            }

            if (isset($seen[$current->id])) {
                break;
            }
            $seen[$current->id] = true;
        }
    }

    private function createAlias(Tag $tag, string $alias, ?string $locale): TagAlias
    {
        $normalized = TagNormalizer::normalize($alias);

        try {
            return DB::transaction(function () use ($tag, $alias, $locale, $normalized): TagAlias {
                $this->lockNames([$normalized]);
                $this->assertAliasFree($tag, $normalized);

                return TagAlias::create([
                    'tag_id' => $tag->id,
                    'alias' => $alias,
                    'normalized_alias' => $normalized,
                    'locale' => $locale,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->assertAliasFree($tag, $normalized);

            throw $e;
        }
    }

    /**
     * @throws ApiException ALIAS_ALREADY_EXISTS
     */
    private function assertAliasFree(Tag $tag, string $normalized): void
    {
        $owner = TagAlias::where('normalized_alias', $normalized)->value('tag_id')
            ?? Tag::where('normalized_name', $normalized)->whereKeyNot($tag->id)->orderBy('id')->value('id');

        if ($owner !== null) {
            throw new ApiException('ALIAS_ALREADY_EXISTS', 409, 'Alias already belongs to a tag', [
                'existing_tag_id' => (int) $owner,
            ]);
        }
    }

    /**
     * Transliterated slug (ADR-0007), suffixed on collision; generated by the
     * backend only. $attempt > 0 after a lost race skips to a fresh suffix.
     */
    private function uniqueSlug(string $name, ?int $exceptTagId, int $attempt): string
    {
        $base = Str::slug($name) ?: 'tag';
        $base = mb_substr($base, 0, 240);

        $taken = Tag::where(fn ($q) => $q->where('slug', $base)->orWhere('slug', 'like', $base.'-%'))
            ->when($exceptTagId !== null, fn ($q) => $q->whereKeyNot($exceptTagId))
            ->pluck('slug')
            ->flip();

        if (! $taken->has($base) && $attempt === 0) {
            return $base;
        }

        for ($n = 2 + $attempt; ; $n++) {
            if (! $taken->has("{$base}-{$n}")) {
                return "{$base}-{$n}";
            }
        }
    }

    /**
     * Retries $work when a concurrent insert took the slug; if the conflict
     * was on the name instead, $recheck turns it into the proper 409.
     *
     * @template T
     *
     * @param  callable(int): T  $work
     * @return T
     */
    private function withSlugRetry(callable $work, callable $recheck): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $work($attempt);
            } catch (UniqueConstraintViolationException $e) {
                $recheck();

                if ($attempt + 1 >= self::SLUG_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param  list<string>  $normalizedNames
     */
    private function lockNames(array $normalizedNames): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return; // sqlite serializes writers already.
        }

        sort($normalizedNames);
        foreach (array_unique($normalizedNames) as $name) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('taxonomy-name:'.$name)]);
        }
    }

    private function tagExists(int $existingTagId): ApiException
    {
        return new ApiException('TAG_ALREADY_EXISTS', 409, 'Tag already exists', [
            'existing_tag_id' => $existingTagId,
        ]);
    }

    private function parentInvalid(string $message): ApiException
    {
        return new ApiException('TAG_PARENT_INVALID', 422, $message);
    }
}
