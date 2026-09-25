<?php

namespace App\Services\Processing;

/**
 * ADR-0009 rules for image_embeddings.model_key/model_version/active.
 */
final class EmbeddingVersion
{
    /**
     * `google/siglip2-so400m-patch14-384` → `siglip2-so400m-patch14-384`.
     */
    public static function modelKey(string $modelName): string
    {
        $slash = strrpos($modelName, '/');

        return $slash === false ? $modelName : substr($modelName, $slash + 1);
    }

    /**
     * True only for the configured active key + version. An unset version
     * activates nothing, so vectors of an unpinned revision are never mixed
     * into search (§77).
     */
    public static function isActive(string $modelKey, string $modelVersion): bool
    {
        $activeKey = config('ai.embedding.active_model_key');
        $activeVersion = config('ai.embedding.active_model_version');

        return is_string($activeKey) && $activeKey !== ''
            && is_string($activeVersion) && $activeVersion !== ''
            && $modelKey === $activeKey
            && $modelVersion === $activeVersion;
    }

    /**
     * pgvector text literal; also stored verbatim in the sqlite test schema.
     *
     * @param  list<float>  $embedding
     */
    public static function toVectorLiteral(array $embedding): string
    {
        return '['.implode(',', array_map(static fn (float $v): string => var_export($v, true), $embedding)).']';
    }
}
