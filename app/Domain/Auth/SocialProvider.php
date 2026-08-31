<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Auth\Exceptions\SocialAuthFailed;

/**
 * One way of signing in through somebody else.
 *
 * An interface with a single implementation today, because the second one is already
 * decided: Apple, once the owner has a developer account. Apple returns the person's
 * name only on the very first authorisation and signs its response with a key you
 * fetch, so it does not fit the shape of a Google callback at all — which is the whole
 * reason the controller talks to this rather than to Google.
 */
interface SocialProvider
{
    /** The short name used in URLs and in config: `google`, `apple`. */
    public function name(): string;

    /** Whether this provider has been given credentials. Absent, its button never appears. */
    public function isConfigured(): bool;

    /** Where to send the browser, including the state we expect back. */
    public function redirectUrl(string $state): string;

    /**
     * Turn the code the provider handed back into who the person is.
     *
     * @throws SocialAuthFailed when the provider refuses, is unreachable, or answers
     *                          with something that is not a usable identity
     */
    public function identify(string $code): SocialIdentity;
}
