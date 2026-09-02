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
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_audit_event_mutation()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION 'Audit events are immutable';
                END;
                $$;
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER prevent_audit_event_mutation
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW
                EXECUTE FUNCTION prevent_audit_event_mutation();
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER prevent_audit_event_updates
                BEFORE UPDATE ON audit_events
                BEGIN
                    SELECT RAISE(ABORT, 'Audit events are immutable');
                END;
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER prevent_audit_event_deletions
                BEFORE DELETE ON audit_events
                BEGIN
                    SELECT RAISE(ABORT, 'Audit events are immutable');
                END;
                SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS prevent_audit_event_mutation ON audit_events');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_audit_event_mutation()');

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS prevent_audit_event_updates');
            DB::unprepared('DROP TRIGGER IF EXISTS prevent_audit_event_deletions');
        }
    }
};
