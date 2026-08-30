<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

/**
 * A sign-in through a provider did not produce somebody we can sign in.
 *
 * Deliberately vague to the person at the browser and specific in the log. Whether the
 * code was replayed, the provider was down, or the account has no verified address is
 * not a distinction worth drawing on a login screen, and some of it would tell an
 * attacker which addresses exist.
 */
final class SocialAuthFailed extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
