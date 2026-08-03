<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 23 Phase 1: user language and theme preferences.
 *
 * Both are stored server-side rather than only in localStorage so that a preference
 * follows the user across devices, and — for the theme — so the server can apply it
 * during the initial render instead of leaving a flash for the client to correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale', 10)->nullable()->after('email');
            $table->string('theme', 10)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['locale', 'theme']);
        });
    }
};
