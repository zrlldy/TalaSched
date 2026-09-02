<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropForeign(['actor_user_id']);
            $table->dropForeign(['impersonator_user_id']);
        });

        $this->restoreSqliteImmutabilityTriggers();
    }

    /**
     * Reverse the migrations.
     *
     * This migration intentionally cannot be rolled back after user deletion:
     * restoring the foreign keys would either reject preserved audit history or
     * reintroduce the mutation that append-only audit records forbid.
     */
    public function down(): void
    {
        throw new LogicException('Restoring audit actor foreign keys would violate preserved immutable audit history.');
    }

    private function restoreSqliteImmutabilityTriggers(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS prevent_audit_event_updates');
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_audit_event_deletions');
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
};
