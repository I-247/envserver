<?php

namespace App\Http\Requests\Variables;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVariableRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'value' => ['sometimes', 'string'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'alias_key' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/\A[A-Za-z_][A-Za-z0-9_]*\z/'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'alias_key.regex' => 'Use letters, digits and underscores only, and do not start with a digit.',
        ];
    }
}
