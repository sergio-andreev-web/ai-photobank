<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DomainMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN_TABLES = [
        'processing_tasks',
        'tag_candidate_images',
        'tag_candidates',
        'image_analyses',
        'tag_embeddings',
        'image_embeddings',
        'image_tag_suppressions',
        'image_tags',
        'tag_aliases',
        'tags',
        'images',
    ];

    public function test_domain_migrations_roll_back_and_reapply_cleanly(): void
    {
        foreach (self::DOMAIN_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected {$table} to exist before rollback");
        }

        // These 11 migrations were added by this task, in creation order; rolling back that
        // many steps must remove exactly this set of tables without touching earlier ones
        // (users, upload_batches, cache, jobs).
        $this->artisan('migrate:rollback', ['--step' => 11])->assertExitCode(0);

        foreach (self::DOMAIN_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "Expected {$table} to be dropped after rollback");
        }

        $this->assertTrue(Schema::hasTable('upload_batches'), 'Rollback should not drop tables from earlier migrations');
        $this->assertTrue(Schema::hasTable('users'), 'Rollback should not drop tables from earlier migrations');

        $this->artisan('migrate')->assertExitCode(0);

        foreach (self::DOMAIN_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected {$table} to exist after re-migrating");
        }
    }
}
