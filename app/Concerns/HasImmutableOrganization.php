<?php

namespace App\Concerns;

use LogicException;

trait HasImmutableOrganization
{
    /**
     * Prevent tenant ownership from being omitted or changed after creation.
     */
    protected static function bootHasImmutableOrganization(): void
    {
        static::creating(function (self $model): void {
            $model->assertOrganizationIsPresent();
        });

        static::updating(function (self $model): void {
            $model->assertOrganizationIsPresent();

            if ($model->isDirty('organization_id')) {
                throw new LogicException('Tenant ownership cannot be changed after creation.');
            }
        });
    }

    /**
     * Ensure the model has a tenant before it reaches the database.
     */
    protected function assertOrganizationIsPresent(): void
    {
        if ($this->getAttribute('organization_id') === null) {
            throw new LogicException('Tenant-owned records require an organization.');
        }
    }
}
