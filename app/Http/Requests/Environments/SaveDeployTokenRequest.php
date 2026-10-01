<?php

namespace App\Http\Requests\Environments;

use App\Support\IpAllowList;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SaveDeployTokenRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * The name is only given when the token is created. Afterwards only the
     * allow list can change: the name is what the audit trail and the OAuth
     * client already know the token by.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->isMethod('POST')
                ? ['required', 'string', 'max:255']
                : ['prohibited'],
            'ip_allowlist' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ($this->allowList()->toArray() as $entry) {
                if (! IpAllowList::isValidEntry($entry)) {
                    $validator->errors()->add('ip_allowlist', __('":input" is not an IP address or CIDR range.', ['input' => $entry]));
                }
            }
        });
    }

    /**
     * Get the addresses the token may pull from.
     *
     * Like the environment's list this is not checked against the submitting
     * address: a deploy server is not the machine you configure it from.
     */
    public function allowList(): IpAllowList
    {
        return IpAllowList::parse($this->string('ip_allowlist')->toString());
    }
}
