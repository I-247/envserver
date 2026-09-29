<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules($this->user()->id),
            // Changing the address is the first step of a takeover: the next
            // is a password reset mailed to the new one. So a session alone,
            // stolen or left open, may not do it.
            'current_password' => $this->emailIsChanging()
                ? $this->currentPasswordRules()
                : ['nullable'],
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
            'current_password.required' => __('Enter your password to change your email address.'),
            'current_password.current_password' => __('That is not your password.'),
        ];
    }

    /**
     * Determine whether the submitted address differs from the current one.
     */
    private function emailIsChanging(): bool
    {
        return strtolower((string) $this->input('email')) !== strtolower($this->user()->email);
    }
}
