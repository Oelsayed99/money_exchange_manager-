<?php

namespace Tests;

use App\Domain\Tenancy\CurrentBusiness;
use App\Models\Business;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The business every test runs inside.
     *
     * There is no unscoped mode. A model belonging to a business refuses to be read or
     * written with nothing bound, so a test that did not open a set of books could not
     * create so much as a currency. Binding one here keeps every existing test saying
     * what it said before, in a world where books are per business.
     *
     * Tests that are *about* isolation create a second business of their own and move
     * between them; see tests/Feature/BusinessIsolationTest.php.
     */
    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();

        app(CurrentBusiness::class)->set($this->business);
    }
}
