<?php

namespace App\Support;

/**
 * PHP cosine similarity for connections without pgvector (the sqlite test
 * schema stores the vector literal as text). PostgreSQL always ranks with
 * pgvector's `<=>` instead; this must give the same `1 - cosine distance`.
 */
final class Vectors
{
    /**
     * Decodes a stored `[x,y,...]` literal (EmbeddingVersion::toVectorLiteral).
     *
     * @return list<float>|null Null when the value is not a vector literal.
     */
    public static function decode(mixed $stored): ?array
    {
        $vector = json_decode((string) $stored, true);

        return is_array($vector) ? array_map(floatval(...), array_values($vector)) : null;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float|int>  $b  Same dimensions as $a.
     */
    public static function cosine(array $a, array $b): float
    {
        $dot = $normA = $normB = 0.0;
        foreach ($a as $i => $value) {
            $other = (float) $b[$i];
            $dot += $value * $other;
            $normA += $value * $value;
            $normB += $other * $other;
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
