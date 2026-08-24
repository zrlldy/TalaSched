<?php

namespace Database\Seeders;

use App\Actions\Organizations\ProvisionOrganizationAuthorization;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class CanonicalAuthorizationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(ProvisionOrganizationAuthorization $provisioner): void
    {
        $permissions = $provisioner->ensureCanonicalPermissions();

        Organization::query()
            ->orderBy('id')
            ->eachById(fn (Organization $organization): mixed => $provisioner->handle($organization, $permissions));
    }
}
