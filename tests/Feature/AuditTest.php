<?php

declare(strict_types=1);

use App\Domain\Audit\AuditRecorder;
use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Currency;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

describe('recording', function (): void {
    it('records a creation with the new values and no old ones', function (): void {
        $currency = Currency::factory()->create(['code' => 'KWD', 'decimal_places' => 3]);

        $entry = $currency->auditLogs()->sole();

        expect($entry->event)->toBe(AuditEvent::Created->value)
            ->and($entry->old_values)->toBeNull()
            ->and($entry->new_values['code'])->toBe('KWD')
            ->and($entry->new_values['decimal_places'])->toBe(3)
            ->and($entry->auditable_type)->toBe(Currency::class)
            ->and($entry->auditable_id)->toBe($currency->id);
    });

    it('records an update with only what actually changed, on both sides', function (): void {
        $currency = Currency::factory()->create(['code' => 'AED', 'decimal_places' => 2, 'sort_order' => 1]);

        $currency->update(['decimal_places' => 3]);

        $entry = $currency->auditLogs()->where('event', AuditEvent::Updated->value)->sole();

        expect(array_keys($entry->new_values))->toBe(['decimal_places'])
            ->and($entry->new_values['decimal_places'])->toBe(3)
            ->and($entry->old_values['decimal_places'])->toBe(2)
            // Untouched columns are noise and stay out of the entry entirely.
            ->and($entry->old_values)->not->toHaveKey('code')
            ->and($entry->old_values)->not->toHaveKey('sort_order');
    });

    it('records a deletion with the values that were lost', function (): void {
        $currency = Currency::factory()->create(['code' => 'XTS']);
        $id = $currency->id;

        $currency->delete();

        $entry = AuditLog::query()
            ->where('auditable_id', $id)
            ->where('event', AuditEvent::Deleted->value)
            ->sole();

        expect($entry->old_values['code'])->toBe('XTS')
            ->and($entry->new_values)->toBeNull();
    });

    it('writes nothing when a save changes nothing', function (): void {
        $currency = Currency::factory()->create();

        $currency->update(['code' => $currency->code]);

        expect($currency->auditLogs()->where('event', AuditEvent::Updated->value)->count())->toBe(0);
    });

    it('keeps identifiers and timestamps out of the recorded values', function (): void {
        $currency = Currency::factory()->create();

        $values = $currency->auditLogs()->sole()->new_values;

        expect($values)->not->toHaveKey('id')
            ->and($values)->not->toHaveKey('created_at')
            ->and($values)->not->toHaveKey('updated_at');
    });
});

describe('attribution', function (): void {
    it('attributes a change to the acting user', function (): void {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create(['email' => 'clerk@example.com']);
        $user->assignRole(Role::Owner->value);

        $this->actingAs($user)->post('/currencies', [
            'code' => 'KWD',
            'name' => 'Kuwaiti Dinar',
            'name_ar' => null,
            'symbol' => 'د.ك',
            'decimal_places' => 3,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $entry = Currency::query()->where('code', 'KWD')->sole()->auditLogs()->sole();

        expect($entry->user_id)->toBe($user->id)
            ->and($entry->actor_label)->toBe('clerk@example.com')
            ->and($entry->source)->toBe('web')
            ->and($entry->ip_address)->not->toBeNull();
    });

    // The trail has to outlive the person. An entry whose actor has been deleted must
    // still say who did it.
    it('stays readable after the acting user is deleted', function (): void {
        $user = User::factory()->create(['email' => 'gone@example.com']);

        $currency = null;
        $this->actingAs($user);
        $currency = Currency::factory()->create(['code' => 'XTS']);

        $user->delete();

        $entry = $currency->auditLogs()->sole();

        expect($entry->actor_label)->toBe('gone@example.com')
            ->and($entry->exists)->toBeTrue();
    });

    it('marks console activity as such', function (): void {
        $currency = Currency::factory()->create();

        $entry = $currency->auditLogs()->sole();

        expect($entry->source)->toBe('console')
            ->and($entry->ip_address)->toBeNull();
    });
});

describe('redaction', function (): void {
    // Knowing that a password changed, and when, is exactly what an audit trail is
    // for. The value is precisely what it must never keep.
    it('records that a password changed without recording the password', function (): void {
        $user = User::factory()->create();

        $user->update(['password' => 'a-completely-new-password']);

        $entry = $user->auditLogs()->where('event', AuditEvent::Updated->value)->sole();

        expect($entry->new_values)->toHaveKey('password')
            ->and($entry->new_values['password'])->toBe(AuditRecorder::REDACTED)
            ->and($entry->old_values['password'])->toBe(AuditRecorder::REDACTED);

        // And the secret appears nowhere in the stored row, in any form.
        $raw = DB::table('audit_logs')->where('id', $entry->id)->sole();

        expect(json_encode($raw))->not->toContain('a-completely-new-password');
    });

    it('redacts every hidden attribute by default', function (): void {
        $user = User::factory()->create();

        expect($user->auditRedacted())->toContain('password')
            ->and($user->auditRedacted())->toContain('remember_token');
    });

    it('does not redact ordinary attributes', function (): void {
        $user = User::factory()->create();

        $user->update(['name' => 'Renamed']);

        $entry = $user->auditLogs()->where('event', AuditEvent::Updated->value)->sole();

        expect($entry->new_values['name'])->toBe('Renamed');
    });
});

describe('immutability', function (): void {
    it('refuses to update an entry through the model', function (): void {
        $entry = Currency::factory()->create()->auditLogs()->sole();

        expect(fn () => $entry->update(['event' => 'tampered']))
            ->toThrow(RuntimeException::class, 'append-only');
    });

    it('refuses to delete an entry through the model', function (): void {
        $entry = Currency::factory()->create()->auditLogs()->sole();

        expect(fn () => $entry->delete())->toThrow(RuntimeException::class, 'append-only');
    });

    // The model guard is convenience. This is the guarantee: raw SQL that bypasses
    // Eloquent entirely is still refused by the database.
    it('refuses an update issued as raw SQL', function (): void {
        $entry = Currency::factory()->create()->auditLogs()->sole();

        expect(fn () => DB::table('audit_logs')->where('id', $entry->id)->update(['event' => 'tampered']))
            ->toThrow(QueryException::class, 'append-only');

        expect(DB::table('audit_logs')->where('id', $entry->id)->value('event'))
            ->toBe(AuditEvent::Created->value);
    });

    it('refuses a delete issued as raw SQL', function (): void {
        $entry = Currency::factory()->create()->auditLogs()->sole();

        expect(fn () => DB::table('audit_logs')->where('id', $entry->id)->delete())
            ->toThrow(QueryException::class, 'append-only');

        expect(DB::table('audit_logs')->where('id', $entry->id)->exists())->toBeTrue();
    });
});

describe('history', function (): void {
    it('accumulates a readable history in reverse order', function (): void {
        $currency = Currency::factory()->create(['code' => 'AED', 'decimal_places' => 2]);
        $currency->update(['decimal_places' => 3]);
        $currency->update(['is_active' => false]);

        $events = $currency->auditLogs()->pluck('event')->all();

        expect($events)->toBe([
            AuditEvent::Updated->value,
            AuditEvent::Updated->value,
            AuditEvent::Created->value,
        ]);
    });
});
