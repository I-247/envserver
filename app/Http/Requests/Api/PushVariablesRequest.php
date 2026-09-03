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
            'variables' => ['required', 'array', 'min:1'],
            'variables.*' => ['present', 'string'],
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
                    if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', (string) $key) !== 1) {
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
