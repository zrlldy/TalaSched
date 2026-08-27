<?php

namespace App\Subscriptions\Contracts;

use App\Subscriptions\Data\BillingWebhook;

interface BillingProvider
{
    /**
     * @param  array<string, string>  $headers
     */
    public function verifyAndNormalizeWebhook(string $payload, array $headers): BillingWebhook;

    public function code(): string;
}
