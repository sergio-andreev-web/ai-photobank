<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaxonomy;
use Tests\Concerns\InteractsAsAdmin;
use Tests\TestCase;

/**
 * The admin UI is Russian-only: VALIDATION_ERROR field messages (`error.details.fields`,
 * ТЗ §51, ADR-0002) come from lang/ru/validation.php. Error codes stay unchanged.
 */
class ValidationLocaleTest extends TestCase
{
    use BuildsTaxonomy;
    use InteractsAsAdmin;
    use RefreshDatabase;

    public function test_default_locale_is_russian(): void
    {
        $this->assertSame('ru', app()->getLocale());
    }

    public function test_rule_messages_use_russian_field_names(): void
    {
        $this->loginAsAdmin();

        $this->postJson('/api/admin/tags', ['name' => '', 'type' => 'object'], $this->csrfHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.details.fields.name.0', 'Поле «название» обязательно.');

        $image = $this->makeImage();
        $this->patchJson("/api/admin/images/{$image->id}", ['original_name' => str_repeat('a', 1025)], $this->csrfHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error.details.fields.original_name.0', 'Поле «имя файла» должно содержать не больше 1024 символов.');
    }

    public function test_application_messages_are_russian(): void
    {
        $this->loginAsAdmin();
        $image = $this->makeImage();

        $this->patchJson("/api/admin/images/{$image->id}", ['original_name' => 'a.jpg', 'foo' => 1], $this->csrfHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error.details.fields._unexpected.0', 'Неизвестные поля: foo.');
    }

    public function test_every_framework_rule_has_a_russian_message(): void
    {
        $english = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $russian = require lang_path('ru/validation.php');

        $missing = array_diff(array_keys(array_diff_key($english, $russian)), ['custom', 'attributes']);
        $this->assertSame([], array_values($missing), 'lang/ru/validation.php lacks rules');

        foreach ($english as $rule => $message) {
            if (is_array($message) && ! in_array($rule, ['custom', 'attributes'], true)) {
                $this->assertSame(array_keys($message), array_keys($russian[$rule]), "rule {$rule}");
            }
        }
    }
}
