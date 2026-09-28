<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_role_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('users', 'role'),
            'Users table should have role column'
        );
    }

    public function test_upload_batches_table_exists(): void
    {
        $this->assertTrue(
            Schema::hasTable('upload_batches'),
            'Upload batches table should exist'
        );
    }

    public function test_upload_batches_table_has_required_columns(): void
    {
        $columns = ['id', 'name', 'status', 'total_images', 'processed_images', 'failed_images', 'created_by', 'created_at', 'updated_at', 'completed_at'];
        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn('upload_batches', $column),
                "Upload batches table should have {$column} column"
            );
        }
    }
}
