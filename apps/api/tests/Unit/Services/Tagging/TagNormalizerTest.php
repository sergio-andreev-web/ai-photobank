<?php

namespace Tests\Unit\Services\Tagging;

use App\Services\Tagging\TagNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ТЗ §35, ADR-0007: NFKC + Unicode lowercase + whitespace collapse; `ё`,
 * punctuation and hyphens are preserved.
 */
class TagNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cases(): array
    {
        return [
            'trim and lowercase' => ['  Protective Helmet ', 'protective helmet'],
            'cyrillic lowercase' => ['КАСКА', 'каска'],
            'collapse inner whitespace' => ["drilling \t\n  platform", 'drilling platform'],
            'nbsp and narrow nbsp' => ["drilling\u{00A0}\u{202F}platform", 'drilling platform'],
            'nfkc ligature' => ["\u{FB01}re", 'fire'],
            'nfkc fullwidth' => ['ＡＢＣ', 'abc'],
            'composed vs decomposed' => ["cafe\u{0301}", "caf\u{00E9}"],
            'yo is kept' => ['Ёлка', 'ёлка'],
            'hyphen and punctuation kept' => ['T-Shirt, Red!', 't-shirt, red!'],
            'whitespace only' => [" \u{00A0}\t", ''],
        ];
    }

    #[DataProvider('cases')]
    public function test_normalize(string $input, string $expected): void
    {
        $this->assertSame($expected, TagNormalizer::normalize($input));
    }

    public function test_normalize_is_idempotent(): void
    {
        $once = TagNormalizer::normalize("  Ｄrilling\u{00A0}PLATFORM ");

        $this->assertSame($once, TagNormalizer::normalize($once));
    }
}
