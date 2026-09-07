<?php

use App\Enums\OrganizationRole;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->owner = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->owner->currentOrganization;
});

test('organization administrators can review redacted, tenant-scoped audit entries', function (): void {
    auditControllerEvent(
        organization: $this->organization,
        actor: $this->owner,
        action: 'schedule.updated',
        subjectType: Organization::class,
        before: ['status' => 'draft', 'private_note' => 'do-not-expose'],
        after: ['status' => 'published', 'capacity' => 40],
    );

    $otherOwner = User::factory()->withOwnedOrganization()->create();
    auditControllerEvent(
        organization: $otherOwner->currentOrganization,
        actor: $otherOwner,
        action: 'other.organization.event',
        subjectType: Organization::class,
        before: ['secret' => 'other-organization-value'],
        after: ['status' => 'active'],
    );

    $response = $this->actingAs($this->owner)
        ->get(route('audits.index', [
            'current_organization' => $this->organization->slug,
        ]));

    $response
        ->assertOk()
        ->assertDontSee('do-not-expose')
        ->assertDontSee('other-organization-value')
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('audits/Index')
            ->where('canViewAudit', true)
            ->where('actions', ['schedule.updated'])
            ->has('entries', 1)
            ->where('entries.0.action', 'schedule.updated')
            ->where('entries.0.actor_name', $this->owner->name)
            ->where('entries.0.subject_label', 'Organization')
            ->where('entries.0.change_fields', ['status', 'private_note', 'capacity'])
            ->missing('entries.0.before')
            ->missing('entries.0.after')
            ->missing('entries.0.actor_user_id')
            ->missing('entries.0.subject_id'));
});

test('audit events support action filters and encrypted cursor pagination', function (): void {
    foreach (range(1, 31) as $index) {
        auditControllerEvent(
            organization: $this->organization,
            actor: $this->owner,
            action: $index === 31 ? 'template.exported' : 'schedule.updated',
            subjectType: Organization::class,
            before: ['status' => 'draft'],
            after: ['status' => 'published'],
            occurredAt: now()->subSeconds($index),
        );
    }

    $filtered = $this->actingAs($this->owner)
        ->get(route('audits.index', [
            'current_organization' => $this->organization->slug,
            'action' => 'template.exported',
        ]));

    $filtered->assertInertia(fn (Assert $page): Assert => $page
        ->where('filters.action', 'template.exported')
        ->has('entries', 1)
        ->where('entries.0.action', 'template.exported')
        ->where('nextCursor', null));

    $response = $this->actingAs($this->owner)
        ->get(route('audits.index', [
            'current_organization' => $this->organization->slug,
        ]));

    $response->assertInertia(fn (Assert $page): Assert => $page
        ->has('entries', 30)
        ->where('nextCursor', fn (mixed $cursor): bool => is_string($cursor) && $cursor !== ''));

    $cursor = data_get($response->viewData('page'), 'props.nextCursor');

    expect($cursor)->toBeString();

    $this->actingAs($this->owner)
        ->get(route('audits.index', [
            'current_organization' => $this->organization->slug,
            'cursor' => $cursor,
        ]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('entries', 1)
            ->where('entries.0.action', 'template.exported'));
});

test('organization members without administrative permission cannot review the audit ledger', function (): void {
    $member = User::factory()->create();
    $this->organization->members()->attach($member, ['role' => OrganizationRole::Member]);

    $this->actingAs($member)
        ->get(route('audits.index', [
            'current_organization' => $this->organization->slug,
        ]))
        ->assertForbidden();
});

test('audit policy permits only organization administrators to view immutable organization records', function (): void {
    $member = User::factory()->create();
    $otherOwner = User::factory()->withOwnedOrganization()->create();
    $this->organization->members()->attach($member, ['role' => OrganizationRole::Member]);
    $event = AuditEvent::factory()
        ->forOrganization($this->organization)
        ->create(['actor_user_id' => $this->owner->getKey()]);

    expect(Gate::forUser($this->owner)->allows('viewAny', [AuditEvent::class, $this->organization]))->toBeTrue()
        ->and(Gate::forUser($this->owner)->allows('view', $event))->toBeTrue()
        ->and(Gate::forUser($member)->allows('viewAny', [AuditEvent::class, $this->organization]))->toBeFalse()
        ->and(Gate::forUser($member)->allows('view', $event))->toBeFalse()
        ->and(Gate::forUser($otherOwner)->allows('view', $event))->toBeFalse()
        ->and(Gate::forUser($this->owner)->allows('create', [AuditEvent::class, $this->organization]))->toBeFalse()
        ->and(Gate::forUser($this->owner)->allows('update', $event))->toBeFalse()
        ->and(Gate::forUser($this->owner)->allows('delete', $event))->toBeFalse();
});

/**
 * @param  array<string, mixed>|null  $before
 * @param  array<string, mixed>|null  $after
 */
function auditControllerEvent(
    Organization $organization,
    User $actor,
    string $action,
    ?string $subjectType,
    ?array $before,
    ?array $after,
    ?CarbonInterface $occurredAt = null,
): void {
    DB::table('audit_events')->insert([
        'organization_id' => $organization->getKey(),
        'actor_user_id' => $actor->getKey(),
        'impersonator_user_id' => null,
        'correlation_id' => (string) Str::uuid(),
        'action' => $action,
        'subject_type' => $subjectType,
        'subject_id' => $organization->public_id,
        'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
        'after' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
        'ip_address' => null,
        'user_agent' => null,
        'occurred_at' => $occurredAt ?? now(),
    ]);
}
