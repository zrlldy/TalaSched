<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculty_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduling_resource_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('employee_number', 64)->nullable();
            $table->string('position')->nullable();
            $table->string('employment_type', 32)->nullable();
            $table->unsignedInteger('maximum_daily_minutes')->nullable();
            $table->unsignedInteger('maximum_weekly_minutes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'employee_number']);
            $table->unique(['organization_id', 'id']);
        });

        Schema::create('faculty_unit_assignments', function (Blueprint $table) {
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('faculty_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_unit_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->primary(['faculty_profile_id', 'academic_unit_id']);
            $table->index(['organization_id', 'academic_unit_id']);
        });

        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campus_academic_unit_id')->nullable()->constrained('academic_units')->nullOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduling_resource_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedInteger('capacity')->nullable();
            $table->string('delivery_mode', 24)->default('physical');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'building_id', 'code']);
            $table->unique(['organization_id', 'id']);
        });

        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('room_features', function (Blueprint $table) {
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->nullable();
            $table->primary(['room_id', 'feature_id']);
        });

        Schema::create('resource_availability_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduling_resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_period_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->unsignedTinyInteger('weekday');
            $table->unsignedSmallInteger('starts_at_minute');
            $table->unsignedSmallInteger('ends_at_minute');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->timestamps();
            $table->index(['organization_id', 'scheduling_resource_id', 'academic_period_id', 'weekday'], 'resource_availability_lookup');
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('code', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('units', 6, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'id']);
        });

        Schema::create('subject_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('name');
            $table->unsignedInteger('weekly_minutes');
            $table->unsignedSmallInteger('sessions_per_week')->default(1);
            $table->unsignedInteger('default_duration_minutes');
            $table->unsignedInteger('minimum_room_capacity')->nullable();
            $table->string('delivery_mode', 24)->default('physical');
            $table->timestamps();
            $table->unique(['organization_id', 'subject_id', 'kind']);
        });

        Schema::create('subject_component_room_types', function (Blueprint $table) {
            $table->foreignId('subject_component_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->primary(['subject_component_id', 'room_type_id']);
        });

        Schema::create('subject_component_features', function (Blueprint $table) {
            $table->foreignId('subject_component_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('minimum_quantity')->nullable();
            $table->primary(['subject_component_id', 'feature_id']);
        });

        Schema::create('subject_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('owning_academic_unit_id')->nullable()->constrained('academic_units')->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('code', 64)->nullable();
            $table->unsignedInteger('expected_enrollment')->default(0);
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'academic_period_id', 'student_group_id']);
        });

        Schema::create('offering_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_component_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 24);
            $table->string('name');
            $table->unsignedInteger('weekly_minutes');
            $table->unsignedSmallInteger('sessions_per_week')->default(1);
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('minimum_room_capacity')->nullable();
            $table->foreignId('required_room_type_id')->nullable()->constrained('room_types')->nullOnDelete();
            $table->string('delivery_mode', 24)->default('physical');
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
        });

        Schema::create('offering_component_features', function (Blueprint $table) {
            $table->foreignId('offering_component_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('minimum_quantity')->nullable();
            $table->primary(['offering_component_id', 'feature_id']);
        });

        Schema::create('offering_instructors', function (Blueprint $table) {
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offering_component_id')->constrained()->cascadeOnDelete();
            $table->foreignId('faculty_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('load_percentage')->default(100);
            $table->boolean('is_primary')->default(false);
            $table->primary(['offering_component_id', 'faculty_profile_id']);
            $table->index(['organization_id', 'faculty_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offering_instructors');
        Schema::dropIfExists('offering_component_features');
        Schema::dropIfExists('offering_components');
        Schema::dropIfExists('subject_offerings');
        Schema::dropIfExists('subject_component_features');
        Schema::dropIfExists('subject_component_room_types');
        Schema::dropIfExists('subject_components');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('resource_availability_rules');
        Schema::dropIfExists('room_features');
        Schema::dropIfExists('features');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('room_types');
        Schema::dropIfExists('faculty_unit_assignments');
        Schema::dropIfExists('faculty_profiles');
    }
};
