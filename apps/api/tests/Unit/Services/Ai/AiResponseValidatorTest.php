<?php

namespace Tests\Unit\Services\Ai;

use App\Contracts\Ai\Exceptions\InvalidAiResponseException;
use App\Services\Ai\AiResponseValidator;
use App\Services\Ai\FakeAiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * §71 contract test: backend validates AI responses against
 * contracts/schemas/image-analysis.json and text-embeddings.json. The
 * contract's own examples must pass, known-bad payloads must not.
 */
class AiResponseValidatorTest extends TestCase
{
    private static function example(string $name): array
    {
        $path = dirname(__DIR__, 6).'/contracts/examples/ai/'.$name;
        if (! is_file($path)) {
            self::markTestSkipped("contracts/ directory not available ({$path}).");
        }

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_contract_analyze_example_is_valid(): void
    {
        $example = self::example('analyze-response.json');

        (new AiResponseValidator)->assertValidImageAnalysis($example, $example['request_id']);

        $this->addToAssertionCount(1);
    }

    public function test_contract_text_embeddings_example_is_valid(): void
    {
        $request = self::example('text-embeddings-request.json');

        (new AiResponseValidator)->assertValidTextEmbeddings(self::example('text-embeddings-response.json'), count($request['texts']));

        $this->addToAssertionCount(1);
    }

    public function test_fake_client_output_satisfies_the_same_schema(): void
    {
        $fake = new FakeAiClient;
        $validator = new AiResponseValidator;

        $validator->assertValidImageAnalysis($fake->analyzeImage('bytes', 'req-1')->toArray(), 'req-1');
        $validator->assertValidTextEmbeddings($fake->createTextEmbeddings(['a', 'b'], 'req-2')->toArray(), 2);

        $this->addToAssertionCount(2);
    }

    /**
     * @return iterable<string, array{0: callable(array): array, 1: string}>
     */
    public static function invalidAnalyses(): iterable
    {
        yield 'shortened embedding (§31 example)' => [fn (array $d) => ['embedding' => [0.123, -0.551]] + $d, '/embedding'];
        yield 'non-numeric embedding value' => [function (array $d) {
            $d['embedding'][5] = 'x';

            return $d;
        }, '/embedding'];
        yield 'unknown concept type' => [function (array $d) {
            $d['concepts'][0]['type'] = 'animal';

            return $d;
        }, '/concepts/0/type'];
        yield 'AI returning a tag id' => [function (array $d) {
            $d['concepts'][0]['tag_id'] = 14;

            return $d;
        }, '/concepts/0/tag_id'];
        yield 'blank concept label' => [function (array $d) {
            $d['concepts'][0]['label'] = '   ';

            return $d;
        }, '/concepts/0/label'];
        yield 'confidence above 1' => [function (array $d) {
            $d['concepts'][0]['confidence'] = 1.5;

            return $d;
        }, '/concepts/0/confidence'];
        yield 'too many concepts' => [fn (array $d) => ['concepts' => array_fill(0, 51, $d['concepts'][0])] + $d, '/concepts'];
        yield 'missing embedding revision (ADR-0009)' => [function (array $d) {
            unset($d['models']['embedding_revision']);

            return $d;
        }, '/models/embedding_revision'];
        yield 'extra top-level field' => [fn (array $d) => $d + ['debug' => true], '/debug'];
        yield 'wrong embedding_dimensions' => [fn (array $d) => ['embedding_dimensions' => 768] + $d, '/embedding_dimensions'];
        yield 'bad prompt_version' => [fn (array $d) => ['prompt_version' => 'Image Analysis'] + $d, '/prompt_version'];
        yield 'negative processing_ms' => [fn (array $d) => ['processing_ms' => -1] + $d, '/processing_ms'];
        yield 'foreign request_id' => [fn (array $d) => ['request_id' => 'someone-else'] + $d, '/request_id'];
        yield 'FastAPI default error body' => [fn (array $d) => ['detail' => [['msg' => 'field required']]], '/request_id'];
        yield 'not an object' => [fn (array $d) => [1, 2, 3], '/:'];
    }

    #[DataProvider('invalidAnalyses')]
    public function test_invalid_analyses_are_rejected(callable $mutate, string $expectedPath): void
    {
        $example = self::example('analyze-response.json');

        try {
            (new AiResponseValidator)->assertValidImageAnalysis($mutate($example), $example['request_id']);
            $this->fail('Expected InvalidAiResponseException');
        } catch (InvalidAiResponseException $e) {
            $this->assertNotEmpty(array_filter($e->violations, fn (string $v) => str_starts_with($v, $expectedPath)), implode("\n", $e->violations));
        }
    }

    public function test_text_embeddings_count_must_match_texts(): void
    {
        $response = self::example('text-embeddings-response.json');

        $this->expectException(InvalidAiResponseException::class);

        (new AiResponseValidator)->assertValidTextEmbeddings($response, count($response['embeddings']) + 1);
    }

    public function test_text_embeddings_dimension_is_enforced(): void
    {
        $response = self::example('text-embeddings-response.json');
        $response['embeddings'][0] = [0.1, 0.2];

        $this->expectException(InvalidAiResponseException::class);

        (new AiResponseValidator)->assertValidTextEmbeddings($response, count($response['embeddings']));
    }
}
