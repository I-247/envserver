<?php

namespace App\Http\Requests\Variables;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request to take an environment's secrets out of the vault in plaintext.
 *
 * The password is asked for every single time rather than through the
 * RequirePassword middleware: that one remembers a confirmation for hours, so
 * the second download of the day would walk straight past the gate. An export
 * hands over every secret at once, which is worth one deliberate act each
 * time.
 */
class DownloadEnvFileRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * Get the validation messages that apply to the request.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.current_password' => __('That is not your password.'),
        ];
    }
}
