<?php

namespace App\Subscriptions;

use App\Audit\AuditLogger;
use App\Enums\BillingWebhookStatus;
use App\Exceptions\BillingWebhookConflictException;
use App\Subscriptions\Data\BillingWebhook;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

class BillingWebhookLedger
{
    private const PROCESSING_LEASE_MINUTES = 5;

    public function __construct(private AuditLogger $auditLogger) {}

    public function claim(BillingWebhook $webhook): bool
    {
        return DB::transaction(function () use ($webhook): bool {
            $payloadHash = $webhook->payloadHash();
            $timestamp = now();
            $inserted = DB::table('billing_webhook_events')->insertOrIgnore([
                'provider' => $webhook->provider,
                'external_id' => $webhook->externalId,
                'event_type' => $webhook->eventType,
                'payload_hash' => $payloadHash,
                'payload' => json_encode($webhook->payload, JSON_THROW_ON_ERROR),
                'status' => BillingWebhookStatus::Processing->value,
                'attempts' => 1,
                'received_at' => $timestamp,
                'processing_until' => $timestamp->copy()->addMinutes(self::PROCESSING_LEASE_MINUTES),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            if ($inserted === 1) {
                $this->recordAudit($webhook, 'billing_webhook.claimed', BillingWebhookStatus::Processing, 1);

                return true;
            }

            $event = $this->lockedEvent($webhook);

            if (! hash_equals((string) $event->payload_hash, $payloadHash)) {
                throw new BillingWebhookConflictException;
            }

            if ($event->status === BillingWebhookStatus::Processed->value) {
                return false;
            }

            if (
                $event->status === BillingWebhookStatus::Processing->value
                && $event->processing_until !== null
                && ! now()->greaterThanOrEqualTo($event->processing_until)
            ) {
                return false;
            }

            $attempts = ((int) $event->attempts) + 1;

            DB::table('billing_webhook_events')
                ->where('id', $event->id)
                ->update([
                    'event_type' => $webhook->eventType,
                    'status' => BillingWebhookStatus::Processing->value,
                    'attempts' => $attempts,
                    'processing_until' => now()->addMinutes(self::PROCESSING_LEASE_MINUTES),
                    'failed_at' => null,
                    'last_error' => null,
                    'updated_at' => now(),
                ]);

            $this->recordAudit($webhook, 'billing_webhook.reclaimed', BillingWebhookStatus::Processing, $attempts);

            return true;
        }, attempts: 3);
    }

    public function complete(BillingWebhook $webhook): void
    {
        $this->transition($webhook, BillingWebhookStatus::Processed, [
            'processed_at' => now(),
            'processing_until' => null,
            'failed_at' => null,
            'last_error' => null,
        ], 'billing_webhook.processed');
    }

    public function fail(BillingWebhook $webhook, string $error): void
    {
        $this->transition($webhook, BillingWebhookStatus::Failed, [
            'processing_until' => null,
            'failed_at' => now(),
            'last_error' => $error,
        ], 'billing_webhook.failed');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(BillingWebhook $webhook, BillingWebhookStatus $status, array $attributes, string $auditAction): void
    {
        DB::transaction(function () use ($webhook, $status, $attributes, $auditAction): void {
            $event = $this->lockedEvent($webhook);

            if (! hash_equals((string) $event->payload_hash, $webhook->payloadHash())) {
                throw new BillingWebhookConflictException;
            }

            if ($event->status === BillingWebhookStatus::Processed->value && $status === BillingWebhookStatus::Processed) {
                return;
            }

            if ($event->status !== BillingWebhookStatus::Processing->value) {
                throw new LogicException('Only a claimed billing webhook can be transitioned.');
            }

            DB::table('billing_webhook_events')
                ->where('id', $event->id)
                ->update($attributes + [
                    'status' => $status->value,
                    'updated_at' => now(),
                ]);

            $this->recordAudit($webhook, $auditAction, $status, (int) $event->attempts);
        }, attempts: 3);
    }

    private function recordAudit(BillingWebhook $webhook, string $action, BillingWebhookStatus $status, int $attempts): void
    {
        $this->auditLogger->record(action: $action, after: [
            'attempts' => $attempts,
            'event_type' => $webhook->eventType,
            'payload_hash' => $webhook->payloadHash(),
            'provider' => $webhook->provider,
            'status' => $status->value,
        ]);
    }

    private function lockedEvent(BillingWebhook $webhook): stdClass
    {
        $event = DB::table('billing_webhook_events')
            ->where('provider', $webhook->provider)
            ->where('external_id', $webhook->externalId)
            ->lockForUpdate()
            ->first();

        if (! $event instanceof stdClass) {
            throw new LogicException('The billing webhook must be claimed before it is transitioned.');
        }

        return $event;
    }
}
