<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The short stretch after a password confirmation in which secrets reveal.
 *
 * Separate from Laravel's own password confirmation on purpose: that one
 * lasts auth.password_timeout (hours) and also covers the security settings,
 * while a reveal should need the password again after a few minutes, so a
 * session cookie that walks off on its own cannot read values one by one.
 */
class SecretAccessWindow
{
    public const SESSION_KEY = 'envserver.secrets_confirmed_at';

    /**
     * Open the window for this session, starting now.
     */
    public static function open(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, now()->getTimestamp());
    }

    /**
     * Determine whether this session confirmed its password recently enough.
     */
    public static function isOpen(Request $request): bool
    {
        $confirmedAt = $request->session()->get(self::SESSION_KEY);

        return is_int($confirmedAt)
            && now()->getTimestamp() - $confirmedAt < self::minutes() * 60;
    }

    /**
     * How long one confirmation lasts.
     */
    public static function minutes(): int
    {
        return max(1, (int) config('envserver.reveal_confirmation_minutes'));
    }
}
