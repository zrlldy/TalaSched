<?php

namespace App\Exceptions;

use RuntimeException;

class BillingWebhookConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A billing webhook external ID was received with a different payload.');
    }
}
