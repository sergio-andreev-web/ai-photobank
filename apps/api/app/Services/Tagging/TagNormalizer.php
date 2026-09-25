<?php

namespace App\Services\Tagging;

use Normalizer;

/**
 * The single normalization function for tag names, aliases and AI concept
 * labels (ТЗ §35, ADR-0007):
 *
 * 1. Unicode NFKC;
 * 2. Unicode lowercase;
 * 3. every whitespace character (NBSP included) → one space, runs collapsed, trimmed.
 *
 * `ё` is not folded into `е`; punctuation and hyphens are kept.
 */
final class TagNormalizer
{
    public static function normalize(string $value): string
    {
        $value = Normalizer::normalize($value, Normalizer::FORM_KC);

        if ($value === false) {
            // Not valid UTF-8: nothing sane to match against.
            return '';
        }

        $value = mb_strtolower($value, 'UTF-8');

        // \s with /u covers Unicode White_Space; NBSP/NNBSP/figure space are listed
        // explicitly since PCRE's \s excludes some no-break spaces on older builds.
        $value = preg_replace('/[\s\x{00A0}\x{2007}\x{202F}\x{FEFF}]+/u', ' ', $value) ?? '';

        return trim($value, ' ');
    }
}
