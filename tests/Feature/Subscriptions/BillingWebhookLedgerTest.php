<?php

use App\Exceptions\BillingWebhookConflictException;
use App\Subscriptions\BillingWebhookLedger;
use App\Subscriptions\Contracts\BillingProvider;
use App\Subscriptions\Data\BillingWebhook;
use Illuminate\Support\Facades\DB;

test('billing provider contracts normalize webhook payloads', function (): void {
    $provider = new class implements BillingProvider
    {
        public function verifyAndNormalizeWebhook(string $payload, array $headers): BillingWebhook
        {
            return new BillingWebhook($this->code(), 'evt_1', 'subscription.updated', json_decode($payload, true, flags: JSON_THROW_ON_ERROR));
        }

        public function code(): string
        {
            return 'fake';
        }
    };

    $webhook = $provider->verifyAndNormalizeWebhook('{"status":"active"}', []);

    expect($provider->code())->toBe('fake')
        ->and($webhook->provider)->toBe('fake')
        ->and($webhook->eventType)->toBe('subscription.updated')
        ->and($webhook->payload)->toBe(['status' => 'active']);
});

test('billing webhook claims are replay safe and transition explicitly', function (): void {
    $ledger = app(BillingWebhookLedger::class);
    $webhook = new BillingWebhook('fake', 'evt_1', 'subscription.updated', [
        'status' => 'active',
        'organization' => ['reference' => 'org_1'],
    ]);

    expect($ledger->claim($webhook))->toBeTrue()
        ->and($ledger->claim($webhook))->toBeFalse();

    expect(DB::table('billing_webhook_events')
        ->where('provider', 'fake')
        ->where('external_id', 'evt_1')
        ->value('attempts'))->toBe(1);

    $ledger->complete($webhook);

    expect(DB::table('billing_webhook_events')
        ->where('provider', 'fake')
        ->where('external_id', 'evt_1')
        ->value('status'))->toBe('processed')
        ->and($ledger->claim($webhook))->toBeFalse();
});

test('failed billing webhook claims may be retried and changed payloads conflict', function (): void {
    $ledger = app(BillingWebhookLedger::class);
    $webhook = new BillingWebhook('fake', 'evt_2', 'subscription.updated', ['status' => 'past_due']);

    expect($ledger->claim($webhook))->toBeTrue();
    $ledger->fail($webhook, 'Temporary provider outage');

    expect($ledger->claim($webhook))->toBeTrue()
        ->and(DB::table('billing_webhook_events')
            ->where('external_id', 'evt_2')
            ->value('attempts'))->toBe(2);

    $conflictingWebhook = new BillingWebhook('fake', 'evt_2', 'subscription.updated', ['status' => 'active']);

    expect(fn () => $ledger->claim($conflictingWebhook))
        ->toThrow(BillingWebhookConflictException::class);
});

test('expired billing webhook processing leases can be reclaimed', function (): void {
    $ledger = app(BillingWebhookLedger::class);
    $webhook = new BillingWebhook('fake', 'evt_3', 'subscription.updated', ['status' => 'active']);

    expect($ledger->claim($webhook))->toBeTrue();

    DB::table('billing_webhook_events')
        ->where('provider', 'fake')
        ->where('external_id', 'evt_3')
        ->update(['processing_until' => now()->subMinute()]);

    expect($ledger->claim($webhook))->toBeTrue()
        ->and(DB::table('billing_webhook_events')
            ->where('external_id', 'evt_3')
            ->value('attempts'))->toBe(2);
});
