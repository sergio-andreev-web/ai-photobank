<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\AiClientInterface;
use App\Services\Ai\FakeAiClient;
use App\Services\Ai\HttpAiClient;
use Tests\TestCase;

/**
 * The test environment forces AI_DRIVER=fake (phpunit.xml) so the whole
 * backend test suite runs without GPU or network (ТЗ §7, AGENT A DoD).
 */
class AiClientBindingTest extends TestCase
{
    public function test_testing_environment_binds_the_fake_driver(): void
    {
        $this->assertSame('fake', config('ai.driver'));
        $this->assertInstanceOf(FakeAiClient::class, $this->app->make(AiClientInterface::class));
    }

    public function test_http_driver_is_bound_when_configured(): void
    {
        config(['ai.driver' => 'http', 'ai.base_url' => 'http://ai.test', 'ai.timeout' => 5]);
        $this->app->forgetInstance(AiClientInterface::class);

        $this->assertInstanceOf(HttpAiClient::class, $this->app->make(AiClientInterface::class));
    }
}
