<?php

namespace App\Http\Requests\Teams;

use App\Enums\AuditAction;
use App\Enums\WebhookKind;
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
     * Hosts a delivery is never sent to.
     *
     * A team admin choosing where their own events go is not a threat, but
     * the server making the request is: an endpoint pointing at localhost or
     * at a cloud metadata address turns the queue worker into a way to reach
     * things only it can see. This is a check on the hostname as written and
     * not a defence against a name that resolves somewhere else later.
     *
     * @var list<string>
     */
    private const BLOCKED_HOSTS = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '169.254.169.254', 'metadata.google.internal'];

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
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $host = strtolower((string) parse_url((string) $this->input('url'), PHP_URL_HOST));

                if (in_array($host, self::BLOCKED_HOSTS, true) || str_starts_with($host, '192.168.') || str_starts_with($host, '10.')) {
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
