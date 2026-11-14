<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Sends the next requests with this Bearer token (forgets any user cached by the guard). */
    public function bearer(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** Next requests without any token. */
    public function guest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutToken();
    }
}
