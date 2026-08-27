<?php

namespace App\Enums;

enum BillingWebhookStatus: string
{
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
}
