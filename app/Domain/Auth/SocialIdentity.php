<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Who a provider says this person is.
 *
 * The three facts every provider returns and the application needs, separated from the
 * shape any particular one returns them in. Apple's payload looks nothing like
 * Google's; neither shape reaches the controller.
 */
final readonly class SocialIdentity
{
    public function __construct(
        public string $provider,
        public string $id,
        public string $email,
        public string $name,
        public bool $emailVerified,
    ) {}
}
