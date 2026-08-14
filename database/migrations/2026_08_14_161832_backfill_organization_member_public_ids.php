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
        DB::table('organization_members')
            ->select('id')
            ->whereNull('public_id')
            ->orderBy('id')
            ->eachById(function (object $membership): void {
                DB::table('organization_members')
                    ->where('id', $membership->id)
                    ->update(['public_id' => (string) Str::uuid()]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Backfilled public identifiers are intentionally retained on rollback.
    }
};
