<?php

namespace App\Http\Requests\Environments;

use App\Support\IpAllowList;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SaveEnvironmentRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * An unchecked checkbox is left out of the payload entirely, so
     * auto_publish has to be optional rather than required.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'auto_publish' => ['nullable', 'boolean'],
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
     * Get the addresses deploy tokens for this environment may pull from.
     *
     * Unlike the team list this one is not checked against the submitting
     * address: the machines that pull from an environment are deliberately
     * not the machine you are configuring it from.
     */
    public function allowList(): IpAllowList
    {
        return IpAllowList::parse($this->string('ip_allowlist')->toString());
    }
}
