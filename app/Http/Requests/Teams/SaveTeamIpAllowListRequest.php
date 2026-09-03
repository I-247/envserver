<?php

namespace App\Http\Requests\Teams;

use App\Support\IpAllowList;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SaveTeamIpAllowListRequest extends FormRequest
{
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
