<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ADR-0002 (contracts 1.1.0): generic codes for requests outside the operations
 * described in contracts/public-api.openapi.yaml.
 */
class GenericHttpErrorsTest extends TestCase
{
    public function test_unknown_api_path_returns_not_found(): void
    {
        $response = $this->getJson('/api/does-not-exist');

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
        $this->assertSame($response->json('error.trace_id'), $response->headers->get('X-Request-ID'));
    }

    public function test_route_constraint_mismatch_returns_not_found(): void
    {
        $response = $this->getJson('/api/admin/images/not-a-number');

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_unsupported_method_returns_method_not_allowed_with_allow_header(): void
    {
        $response = $this->deleteJson('/api/admin/batches');

        $response->assertStatus(405);
        $response->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
        $this->assertStringContainsString('GET', (string) $response->headers->get('Allow'));
        $this->assertStringContainsString('POST', (string) $response->headers->get('Allow'));
        $this->assertSame($response->json('error.trace_id'), $response->headers->get('X-Request-ID'));
    }

    public function test_non_api_paths_keep_default_rendering(): void
    {
        $response = $this->getJson('/definitely-missing');

        $response->assertStatus(404);
        $this->assertNull($response->json('error.code'));
    }
}
