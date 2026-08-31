<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to record that a person signs in through Google rather than a password.
 *
 * Two columns rather than a table of linked identities, because a person signs in one
 * way here. If that ever stops being true — the same owner reaching their books through
 * Google on a laptop and Apple on a phone — this becomes a `social_accounts` table and
 * these columns move into it.
 *
 * The password becomes nullable. Somebody who has only ever signed in through Google
 * has no password, and storing an unusable hash to avoid a null would mean a value that
 * looks like a credential and is not one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('oauth_provider', 20)->nullable()->after('password');
            $table->string('oauth_id')->nullable()->after('oauth_provider');

            // Two people cannot be the same Google account, and the same person signing
            // in twice must land on the same row.
            $table->unique(['oauth_provider', 'oauth_id'], 'users_oauth_identity_unique');
        });

        DB::statement('ALTER TABLE users MODIFY password VARCHAR(255) NULL');
    }

    public function down(): void
    {
        // Anyone who signs in through a provider has no password, and would be locked
        // out by a column that insists on one.
        if (DB::table('users')->whereNull('password')->exists()) {
            throw new RuntimeException(
                'Some accounts sign in through a provider and have no password. Making the '
                .'column NOT NULL again would lock them out with no way back in. Give them '
                .'passwords first, or accept that this migration does not reverse.'
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_oauth_identity_unique');
            $table->dropColumn(['oauth_provider', 'oauth_id']);
        });

        DB::statement('ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL');
    }
};
