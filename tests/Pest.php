<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Integration tests commit their data, because they need a second connection to see
// it. Truncation rather than a rolled-back transaction is what makes that possible.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Integration');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A user holding a role, with roles and permissions seeded.
 *
 * RefreshDatabase truncates between tests, so the role table has to be rebuilt per
 * test rather than once. Seeding from the same seeder the application ships means a
 * test can never pass against a permission set that production does not have.
 */
function userWithRole(Role $role): User
{
    (new RolePermissionSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

/** A user holding no role at all, for asserting that access fails closed. */
function userWithoutRole(): User
{
    (new RolePermissionSeeder)->run();

    return User::factory()->create();
}
