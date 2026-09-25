<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * contracts/public-api.openapi.yaml#/components/schemas/LoginRequest (additionalProperties: false —
 * ADR-0001 removed the "remember" field entirely).
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unknown = array_diff(array_keys($this->all()), ['email', 'password']);

            if ($unknown !== []) {
                $validator->errors()->add('_unexpected', 'Unexpected field(s): '.implode(', ', $unknown));
            }
        });
    }
}
