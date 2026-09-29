<?php

namespace App\Http\Requests\Teams;

use App\Concerns\PasswordValidationRules;
use App\Support\IpAllowList;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request to change which networks can reach a team.
 *
 * The route stays outside the team's own allow list on purpose (see
 * routes.md), so it is the one change a session from a refused network can
 * still make. The password is asked every time, like the two-factor switch:
 * a stolen session cookie alone must not be able to clear the list.
 */
class SaveTeamIpAllowListRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * The field is one textarea, not a repeater: an operator pastes a list of
     * ranges rather than adding them one at a time.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ip_allowlist' => ['nullable', 'string', 'max:5000'],
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
            $allowList = $this->allowList();

            foreach ($allowList->toArray() as $entry) {
                if (! IpAllowList::isValidEntry($entry)) {
                    $validator->errors()->add('ip_allowlist', __('":input" is not an IP address or CIDR range.', ['input' => $entry]));
                }
            }

            // Saving a list you are not on is how a team locks itself out of
            // its own vault, so it is rejected rather than warned about.
            if ($validator->errors()->isEmpty() && $allowList->isNotEmpty() && ! $allowList->allows($this->ip())) {
                $validator->errors()->add('ip_allowlist', __('Add your own address (:ip) to the list, otherwise you lock yourself out.', ['ip' => $this->ip()]));
            }
        });
    }

    /**
     * Get the submitted list.
     */
    public function allowList(): IpAllowList
    {
        return IpAllowList::parse($this->string('ip_allowlist')->toString());
    }
}
