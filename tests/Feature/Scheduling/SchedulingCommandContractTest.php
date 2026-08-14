<?php

use App\Exceptions\StaleWriteException;
use App\Models\IdempotencyRecord;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

test('correlation identifiers are accepted when valid and returned on responses', function () {
    $correlationId = (string) Str::uuid();

    $this->withHeader('X-Correlation-ID', $correlationId)
        ->get(route('home'))
        ->assertSuccessful()
        ->assertHeader('X-Correlation-ID', $correlationId);
});

test('stale writes use the standard conflict envelope', function () {
    $correlationId = (string) Str::uuid();
    $request = Request::create('/scheduling/entries/example', 'PATCH');
    $request->attributes->set('correlation_id', $correlationId);

    $response = (new StaleWriteException([
        'id' => (string) Str::uuid(),
        'lock_version' => 4,
    ]))->render($request);

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true)['error']['code'])->toBe('stale_write')
        ->and($response->getData(true)['error']['current']['lock_version'])->toBe(4)
        ->and($response->getData(true)['meta']['correlation_id'])->toBe($correlationId);
});

test('unauthenticated scheduling commands use the standard error envelope', function () {
    $organization = Organization::factory()->create();

    $response = $this->postJson(
        route('scheduling.entries.validate', $organization),
        [],
    );

    $response->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertJsonPath('meta.correlation_id', $response->headers->get('X-Correlation-ID'));
});

test('cross-organization scheduling commands use a non-disclosing forbidden envelope', function () {
    $user = User::factory()->create();
    $otherOrganization = Organization::factory()->create();

    $response = $this->actingAs($user)->postJson(
        route('scheduling.entries.validate', $otherOrganization),
        [],
    );

    $response->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonPath('error.message', 'You are not authorized to perform this action.')
        ->assertJsonPath('meta.correlation_id', $response->headers->get('X-Correlation-ID'));
});

test('idempotency record factories preserve tenant ownership', function () {
    $record = IdempotencyRecord::factory()->create();
    $actor = User::query()->findOrFail($record->actor_user_id);

    expect($record->organization_id)->toBe($actor->current_organization_id)
        ->and($record->key_hash)->toHaveLength(64)
        ->and($record->request_hash)->toHaveLength(64);
});
