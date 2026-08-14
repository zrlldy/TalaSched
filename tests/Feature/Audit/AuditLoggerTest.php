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
