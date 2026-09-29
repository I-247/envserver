<?php

namespace App\Http\Requests\Teams;

use App\Enums\AuditAction;
use App\Enums\WebhookKind;
use App\Support\PublicAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new place to send this team's audit events to.
 */
class SaveWebhookEndpointRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(WebhookKind::class)],
            // https only: the body names projects, environments and keys, and
            // an audit trail travelling in the clear is worth less than one
            // that never left.
            'url' => ['required', 'url:https', 'max:2048'],
            'events' => ['sometimes', 'array'],
            'events.*' => [Rule::enum(AuditAction::class)],
        ];
    }

    /**
     * Reject an endpoint aimed at the server itself.
     *
     * A team admin choosing where their own events go is not a threat, but
     * the server making the request is: an endpoint pointing at localhost or
     * at a cloud metadata address turns the queue worker into a way to reach
     * things only it can see. The host is resolved here, and again by
     * DeliverWebhook on every send, because a name can be repointed later.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('url')) {
                    return;
                }

                if (app(PublicAddress::class)->addressesFor((string) $this->input('url')) === null) {
                    $validator->errors()->add('url', 'That address is on the server\'s own network, so a delivery would never leave it.');
                }
            },
        ];
    }

    /**
     * Get the actions this endpoint wants, empty meaning all of them.
     *
     * @return list<string>
     */
    public function events(): array
    {
        /** @var list<string> $events */
        $events = array_values(array_unique($this->input('events', [])));

        return $events;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.url' => 'Use an https address.',
        ];
    }
}
