<?php

namespace App\Http\Requests\Audit;

use App\Enums\AuditAction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FilterAuditEventsRequest extends FormRequest
{
    /**
     * The value the actor filter uses for events without a user behind them.
     */
    public const SYSTEM_ACTOR = 'system';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /**
             * A user id, or the system sentinel for events we recorded
             * without an actor. Kept loose on purpose: an actor who left the
             * team still has events worth filtering on.
             */
            'actor' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', Rule::enum(AuditAction::class)],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Get the filters as the audit query wants them.
     *
     * @return array{actor: string|null, action: AuditAction|null, search: string|null}
     */
    public function filters(): array
    {
        $search = trim((string) $this->string('search'));

        return [
            'actor' => $this->filled('actor') ? (string) $this->string('actor') : null,
            'action' => $this->enum('action', AuditAction::class),
            'search' => $search === '' ? null : $search,
        ];
    }
}
