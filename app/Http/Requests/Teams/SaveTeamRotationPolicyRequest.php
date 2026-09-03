<?php

namespace App\Http\Requests\Teams;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request to change how long the team's secrets may go unchanged.
 *
 * No password here, unlike the two-factor switch and the .env export: this
 * setting widens or narrows what the dashboard reports, never who may reach
 * a value. Turning it off is still written to the audit trail, which is the
 * protection that fits the size of the decision.
 */
class SaveTeamRotationPolicyRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Capped at ten years: past that the number says "never" while
            // pretending to be a policy, and "never" is what null is for.
            'default_rotate_after_days' => ['present', 'nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }

    /**
     * Get the interval to store, with null meaning no policy at all.
     */
    public function days(): ?int
    {
        $days = $this->input('default_rotate_after_days');

        return $days === null || $days === '' ? null : (int) $days;
    }
}
