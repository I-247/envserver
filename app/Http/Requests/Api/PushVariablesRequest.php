<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class PushVariablesRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Generous for a real .env, but a single request can no longer
            // turn into tens of thousands of versions and releases.
            'variables' => ['required', 'array', 'min:1', 'max:1000'],
            'variables.*' => ['present', 'string', 'max:65535'],
        ];
    }

    /**
     * Reject keys a shell or phpdotenv would not accept.
     *
     * Validated here rather than in a rule on variables.* because the problem
     * is with the key, not the value, and the error has to point at the key.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach (array_keys((array) $this->input('variables', [])) as $key) {
                    if (strlen((string) $key) > 255 || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', (string) $key) !== 1) {
                        $validator->errors()->add(
                            "variables.{$key}",
                            "[{$key}] is not a valid environment variable name.",
                        );
                    }
                }
            },
        ];
    }
}
