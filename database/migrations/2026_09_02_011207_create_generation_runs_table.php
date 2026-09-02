<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timetable_id');
            $table->foreignId('source_timetable_version_id');
            $table->foreignId('output_timetable_version_id')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->unsignedSmallInteger('input_schema_version')->default(1);
            $table->json('input_snapshot');
            $table->bigInteger('seed')->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('progress_percent')->nullable();
            $table->string('progress_message')->nullable();
            $table->json('diagnostics')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign(['organization_id', 'timetable_id'], 'generation_run_timetable_tenant_fk')
                ->references(['organization_id', 'id'])
                ->on('timetables')
                ->noActionOnDelete();
            $table->foreign(['organization_id', 'source_timetable_version_id'], 'generation_run_source_version_tenant_fk')
                ->references(['organization_id', 'id'])
                ->on('timetable_versions')
                ->noActionOnDelete();
            $table->foreign(['organization_id', 'output_timetable_version_id'], 'generation_run_output_version_tenant_fk')
                ->references(['organization_id', 'id'])
                ->on('timetable_versions')
                ->noActionOnDelete();
            $table->index(['organization_id', 'status', 'created_at'], 'generation_run_status_lookup');
            $table->index(['organization_id', 'timetable_id', 'created_at'], 'generation_run_timetable_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_runs');
    }
};
