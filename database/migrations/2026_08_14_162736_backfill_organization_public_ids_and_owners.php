<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('organizations')
            ->select('id')
            ->whereNull('public_id')
            ->orderBy('id')
            ->eachById(function (object $organization): void {
                DB::table('organizations')
                    ->where('id', $organization->id)
                    ->update(['public_id' => (string) Str::uuid()]);
            });

        DB::table('organizations')
            ->select('id')
            ->whereNull('owner_user_id')
            ->orderBy('id')
            ->eachById(function (object $organization): void {
                $ownerUserId = DB::table('organization_members')
                    ->where('organization_id', $organization->id)
                    ->where('role', 'owner')
                    ->orderBy('id')
                    ->value('user_id');

                if ($ownerUserId === null) {
                    throw new RuntimeException("Organization {$organization->id} has no owner membership to backfill.");
                }

                DB::table('organizations')
                    ->where('id', $organization->id)
                    ->update(['owner_user_id' => $ownerUserId]);
            });

        $hasMissingIdentity = DB::table('organizations')
            ->whereNull('public_id')
            ->orWhereNull('owner_user_id')
            ->exists();

        if ($hasMissingIdentity) {
            throw new RuntimeException('Organization identity backfill did not complete.');
        }

        $hasInvalidOwner = DB::table('organizations as organizations')
            ->leftJoin('organization_members as owner_membership', function ($join): void {
                $join->on('owner_membership.organization_id', '=', 'organizations.id')
                    ->on('owner_membership.user_id', '=', 'organizations.owner_user_id')
                    ->where('owner_membership.role', '=', 'owner');
            })
            ->whereNull('owner_membership.id')
            ->exists();

        if ($hasInvalidOwner) {
            throw new RuntimeException('Every organization owner must have an owner membership.');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Backfilled organization identities are intentionally retained on rollback.
    }
};
