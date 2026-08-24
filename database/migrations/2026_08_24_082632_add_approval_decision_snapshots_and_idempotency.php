<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('approval_instances', function (Blueprint $table): void {
            $table->dropUnique(['timetable_version_id']);
            $table->index(['organization_id', 'timetable_version_id', 'status'], 'approval_instance_version_status_index');
        });

        Schema::table('approval_instance_steps', function (Blueprint $table): void {
            $table->string('approver_selector_type', 32)->nullable();
            $table->string('required_permission', 120)->nullable();
            $table->json('approver_role_codes')->nullable();
        });

        Schema::table('approval_actions', function (Blueprint $table): void {
            $table->string('idempotency_key', 120)->nullable();
            $table->unique(['organization_id', 'idempotency_key'], 'approval_action_idempotency_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('approval_actions', function (Blueprint $table): void {
            $table->dropUnique('approval_action_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });

        Schema::table('approval_instance_steps', function (Blueprint $table): void {
            $table->dropColumn(['approver_selector_type', 'required_permission', 'approver_role_codes']);
        });

        Schema::table('approval_instances', function (Blueprint $table): void {
            $table->dropIndex('approval_instance_version_status_index');
            $table->unique('timetable_version_id');
        });
    }
};
