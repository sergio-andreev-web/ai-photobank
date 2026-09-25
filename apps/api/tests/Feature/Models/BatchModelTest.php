<?php

namespace Tests\Feature\Models;

use App\Models\Batch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_can_be_created(): void
    {
        $user = User::factory()->create();
        $batch = Batch::create([
            'name' => 'Test Batch',
            'status' => 'created',
            'total_images' => 0,
            'processed_images' => 0,
            'failed_images' => 0,
            'created_by' => $user->id,
        ]);

        $this->assertNotNull($batch->id);
        $this->assertEquals('Test Batch', $batch->name);
        $this->assertEquals('created', $batch->status);
    }

}
