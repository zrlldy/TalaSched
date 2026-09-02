<?php

use App\Audit\AuditLogger;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('audit events cannot be updated or deleted', function (): void {
    $organization = Organization::factory()->create();

    app(AuditLogger::class)->record(
        action: 'audit.immutability_verified',
        organization: $organization,
        subject: $organization,
    );

    $auditEventId = DB::table('audit_events')
        ->where('action', 'audit.immutability_verified')
        ->value('id');

    expect($auditEventId)->not->toBeNull();

    expect(fn () => DB::table('audit_events')
        ->where('id', $auditEventId)
        ->update(['action' => 'audit.mutated']))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('audit_events')
        ->where('id', $auditEventId)
        ->delete())
        ->toThrow(QueryException::class);

    expect(DB::table('audit_events')->where('id', $auditEventId)->value('action'))
        ->toBe('audit.immutability_verified');
});

test('deleting an account preserves its global audit actor identifier', function (): void {
    $user = User::factory()->create();

    app(AuditLogger::class)->record(
        action: 'account.registered',
        actor: $user,
        subject: $user,
    );

    $auditEventId = DB::table('audit_events')
        ->where('action', 'account.registered')
        ->value('id');

    expect($auditEventId)->not->toBeNull();

    $user->delete();

    $auditEvent = DB::table('audit_events')->find($auditEventId);

    expect($auditEvent)->not->toBeNull()
        ->and($auditEvent->actor_user_id)->toBe($user->id)
        ->and($auditEvent->subject_id)->toBe((string) $user->id)
        ->and($auditEvent->action)->toBe('account.registered');
});
