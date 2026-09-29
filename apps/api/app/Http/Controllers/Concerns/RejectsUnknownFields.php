<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Request schemas in contracts/public-api.openapi.yaml use
 * `additionalProperties: false`; an unexpected field is a VALIDATION_ERROR.
 */
trait RejectsUnknownFields
{
    /**
     * @param  list<string>  $allowed
     */
    protected function rejectUnknownFields(Request $request, array $allowed, bool $requireOne = false): void
    {
        $fields = array_keys($request->json()->all());

        $unknown = array_diff($fields, $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                '_unexpected' => __('validation.unexpected_fields', ['fields' => implode(', ', $unknown)]),
            ]);
        }

        if ($requireOne && $fields === []) {
            throw ValidationException::withMessages([
                '_empty' => __('validation.at_least_one_field', ['fields' => implode(', ', $allowed)]),
            ]);
        }
    }
}
