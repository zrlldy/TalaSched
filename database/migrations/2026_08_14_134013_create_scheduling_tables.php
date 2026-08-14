<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_period_id')->constrained()->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('scheduling_granularity');
            $table->timestamps();
            $table->unique(['organization_id', 'academic_period_id']);
            $table->unique(['organization_id', 'id']);
        });

        Schema::create('timetable_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timetable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('based_on_version_id')->nullable()->constrained('timetable_versions')->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->unsignedInteger('version_number');
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['timetable_id', 'version_number']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('constraint_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('name');
            $table->string('handler');
            $table->string('default_severity', 16);
            $table->unsignedSmallInteger('configuration_schema_version')->default(1);
            $table->boolean('is_mandatory')->default(false);
            $table->timestamps();
        });

        Schema::create('constraint_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('constraint_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_unit_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('academic_period_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->string('severity', 16);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->decimal('weight', 10, 3)->default(1);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('configuration')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'academic_period_id', 'is_enabled'], 'constraint_config_lookup');
        });

        Schema::create('schedule_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timetable_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offering_component_id')->constrained()->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->uuid('logical_id');
            $table->unsignedTinyInteger('weekday');
            $table->unsignedSmallInteger('starts_at_minute');
            $table->unsignedSmallInteger('ends_at_minute');
            $table->string('delivery_mode', 24)->default('physical');
            $table->text('notes')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['timetable_version_id', 'logical_id']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'timetable_version_id', 'weekday', 'starts_at_minute'], 'schedule_entry_grid_lookup');
        });

        Schema::create('schedule_entry_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduling_resource_id')->constrained()->restrictOnDelete();
            $table->string('role', 24);
            $table->timestamps();
            $table->unique(['schedule_entry_id', 'scheduling_resource_id', 'role'], 'schedule_entry_resource_unique');
            $table->index(['organization_id', 'scheduling_resource_id']);
        });

        Schema::create('schedule_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timetable_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduling_resource_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->unsignedSmallInteger('starts_at_minute');
            $table->unsignedSmallInteger('ends_at_minute');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['schedule_entry_id', 'scheduling_resource_id']);
            $table->index(['organization_id', 'timetable_version_id', 'scheduling_resource_id', 'weekday'], 'schedule_reservation_conflict_lookup');
        });

        Schema::create('schedule_entry_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_entry_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->date('date');
            $table->string('action', 24);
            $table->unsignedSmallInteger('starts_at_minute')->nullable();
            $table->unsignedSmallInteger('ends_at_minute')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['schedule_entry_id', 'date']);
            $table->index(['organization_id', 'date']);
        });

        Schema::create('schedule_exception_resources', function (Blueprint $table) {
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_entry_exception_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduling_resource_id')->constrained()->restrictOnDelete();
            $table->string('role', 24);
            $table->primary(['schedule_entry_exception_id', 'scheduling_resource_id', 'role'], 'schedule_exception_resource_pk');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement("CREATE UNIQUE INDEX timetable_one_published_version ON timetable_versions (timetable_id) WHERE status = 'published'");
            DB::statement("ALTER TABLE schedule_reservations ADD COLUMN minute_range int4range GENERATED ALWAYS AS (int4range(starts_at_minute, ends_at_minute, '[)')) STORED");
            DB::statement('ALTER TABLE schedule_reservations ADD CONSTRAINT schedule_resource_no_overlap EXCLUDE USING gist (organization_id WITH =, timetable_version_id WITH =, scheduling_resource_id WITH =, weekday WITH =, minute_range WITH &&) WHERE (is_active)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_exception_resources');
        Schema::dropIfExists('schedule_entry_exceptions');
        Schema::dropIfExists('schedule_reservations');
        Schema::dropIfExists('schedule_entry_resources');
        Schema::dropIfExists('schedule_entries');
        Schema::dropIfExists('constraint_configurations');
        Schema::dropIfExists('constraint_definitions');
        Schema::dropIfExists('timetable_versions');
        Schema::dropIfExists('timetables');
    }
};
