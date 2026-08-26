<?php

namespace App\Enums;

enum WebhookKind: string
{
    /**
     * A signed JSON body, for anything you wrote yourself.
     */
    case Json = 'json';

    /**
     * A Slack incoming webhook, which wants one "text" field and ignores
     * everything else.
     */
    case Slack = 'slack';

    /**
     * Get the human readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Json => 'Signed JSON',
            self::Slack => 'Slack',
        };
    }

    /**
     * Determine whether deliveries of this kind carry a signature.
     *
     * Slack has no way to check one and every field beyond "text" is dropped
     * on the floor, so signing it would be ceremony rather than protection.
     * The URL is the secret there, which is Slack's design, not ours.
     */
    public function isSigned(): bool
    {
        return $this === self::Json;
    }
}
