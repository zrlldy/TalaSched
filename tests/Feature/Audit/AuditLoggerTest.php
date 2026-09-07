<?php

use App\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Http\Request;
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

test('audit events capture safe request metadata and explicit change summaries', function (): void {
    $actor = User::factory()->withOwnedOrganization()->create();
    $organization = $actor->currentOrganization;
    $originalRequest = app('request');
    $request = Request::create('/audit-metadata', 'POST', ['access_token' => 'never-store-this'], [], [], [
        'HTTP_USER_AGENT' => 'TalaSched audit metadata test',
        'REMOTE_ADDR' => '203.0.113.42',
    ]);

    app()->instance('request', $request);

    try {
        app(AuditLogger::class)->record(
            action: 'test.metadata',
            organization: $organization,
            actor: $actor,
            subject: $organization,
            before: ['status' => 'pending'],
            after: ['status' => 'active'],
        );
    } finally {
        app()->instance('request', $originalRequest);
    }

    $auditEvent = DB::table('audit_events')->where('action', 'test.metadata')->first();

    expect($auditEvent)->not->toBeNull()
        ->and($auditEvent->organization_id)->toBe($organization->id)
        ->and($auditEvent->actor_user_id)->toBe($actor->id)
        ->and($auditEvent->subject_id)->toBe($organization->public_id)
        ->and($auditEvent->ip_address)->toBe('203.0.113.42')
        ->and($auditEvent->user_agent)->toBe('TalaSched audit metadata test')
        ->and(json_decode((string) $auditEvent->before, true, flags: JSON_THROW_ON_ERROR))->toBe(['status' => 'pending'])
        ->and(json_decode((string) $auditEvent->after, true, flags: JSON_THROW_ON_ERROR))->toBe(['status' => 'active'])
        ->and(json_encode($auditEvent, JSON_THROW_ON_ERROR))->not->toContain('never-store-this');
});
