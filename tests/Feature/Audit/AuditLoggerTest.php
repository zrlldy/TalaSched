<?php

use App\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('audit events reuse the active request correlation identifier', function () {
    $user = User::factory()->withOwnedOrganization()->create();
    $correlationId = (string) Str::uuid();
    Context::add('correlation_id', $correlationId);

    try {
        app(AuditLogger::class)->record(
            action: 'test.correlation',
            organization: $user->currentOrganization,
            actor: $user,
            subject: $user->currentOrganization,
        );
    } finally {
        Context::forget('correlation_id');
    }

    $auditEvent = DB::table('audit_events')->where('action', 'test.correlation')->first();

    expect($auditEvent)->not->toBeNull()
        ->and($auditEvent->correlation_id)->toBe($correlationId)
        ->and($auditEvent->subject_id)->toBe($user->currentOrganization->public_id);
});

test('audit events record the active impersonator context', function () {
    $actor = User::factory()->withOwnedOrganization()->create();
    $impersonator = User::factory()->create();
    Context::add('impersonator_user_id', $impersonator->id);

    try {
        app(AuditLogger::class)->record(
            action: 'test.impersonation',
            organization: $actor->currentOrganization,
            actor: $actor,
            subject: $actor->currentOrganization,
        );
    } finally {
        Context::forget('impersonator_user_id');
    }

    $auditEvent = DB::table('audit_events')->where('action', 'test.impersonation')->first();

    expect($auditEvent)->not->toBeNull()
        ->and($auditEvent->actor_user_id)->toBe($actor->id)
        ->and($auditEvent->impersonator_user_id)->toBe($impersonator->id);
});
