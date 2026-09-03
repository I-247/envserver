<?php

namespace App\Http\Requests\Teams;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request to change who can reach a team's vault at all.
 *
 * The password is asked for on every flip rather than through the
 * RequirePassword middleware, for the same reason the .env export does it:
 * that middleware remembers a confirmation for hours, and this switch is
 * worth one deliberate act each time. Turning the requirement off is the
 * direction that widens access, so it is gated just as tightly as turning
 * it on.
 */
class SaveTeamTwoFactorRequirementRequest extends FormRequest
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
            'two_factor_required' => ['required', 'boolean'],
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

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // The same guard the IP allow list carries: switching this on
            // without a second factor of your own locks you out of the vault
            // you were trying to protect, one redirect after saving.
            if ($this->boolean('two_factor_required') && ! $this->user()->hasSecondFactor()) {
                $validator->errors()->add(
                    'two_factor_required',
                    __('Set up an authenticator app or a passkey for your own account first, otherwise you lock yourself out.'),
                );
            }
        });
    }
}
