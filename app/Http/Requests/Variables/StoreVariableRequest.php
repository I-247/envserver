<?php

namespace App\Http\Requests\Variables;

use App\Actions\Variables\ResolveEnvironmentVariables;
use App\Data\ResolvedVariable;
use App\Models\Environment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreVariableRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z_][A-Za-z0-9_]*\z/'],
            'value' => ['present', 'string'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Reject a key this environment already exposes.
     *
     * Two variables may share a key within a team, but never within one
     * environment: the .env can only hold one of them, and the other would
     * sit there shadowed and confusing.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $environment = $this->route('environment');

                if (! $environment instanceof Environment) {
                    return;
                }

                $taken = app(ResolveEnvironmentVariables::class)
                    ->handle($environment)
                    ->contains(fn (ResolvedVariable $entry) => $entry->key === $this->input('key'));

                if ($taken) {
                    $validator->errors()->add('key', 'This environment already has a variable with that name.');
                }
            },
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
            'key.regex' => 'Use letters, digits and underscores only, and do not start with a digit.',
        ];
    }
}
