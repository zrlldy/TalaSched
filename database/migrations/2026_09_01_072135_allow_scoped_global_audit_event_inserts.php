<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE POLICY audit_global_event_inserts ON audit_events
            FOR INSERT
            WITH CHECK (
                organization_id IS NULL
                AND current_setting('app.allow_global_audit_event_insert', true) = 'true'
            )
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP POLICY IF EXISTS audit_global_event_inserts ON audit_events');
    }
};
