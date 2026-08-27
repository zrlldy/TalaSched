<?php

namespace App\Subscriptions\Data;

use InvalidArgumentException;

final readonly class BillingWebhook
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $provider,
        public string $externalId,
        public string $eventType,
        public array $payload,
    ) {
        if ($provider === '' || $externalId === '' || $eventType === '') {
            throw new InvalidArgumentException('Billing webhook identifiers and event type are required.');
        }
    }

    public function payloadHash(): string
    {
        return hash('sha256', json_encode($this->canonicalize($this->payload), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
