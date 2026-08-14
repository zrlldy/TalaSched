<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 24)->default('draft');
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
            $table->unique(['organization_id', 'id']);
        });

        Schema::create('academic_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->string('kind', 24);
            $table->unsignedSmallInteger('sequence');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
            $table->unique(['organization_id', 'academic_year_id', 'sequence']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'starts_on', 'ends_on']);
        });

        Schema::create('academic_unit_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'id']);
        });

        Schema::create('academic_unit_type_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_type_id')->nullable()->constrained('academic_unit_types')->cascadeOnDelete();
            $table->foreignId('child_type_id')->constrained('academic_unit_types')->cascadeOnDelete();
            $table->unique(['organization_id', 'parent_type_id', 'child_type_id'], 'academic_unit_type_edge_unique');
        });

        Schema::create('academic_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_unit_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('academic_units')->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('code', 64)->nullable();
            $table->string('name');
            $table->date('active_from')->nullable();
            $table->date('active_until')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'parent_id']);
        });

        Schema::table('membership_role_assignments', function (Blueprint $table) {
            $table->foreign(['organization_id', 'academic_unit_id'], 'membership_role_unit_tenant_fk')
                ->references(['organization_id', 'id'])->on('academic_units')->cascadeOnDelete();
        });

        Schema::create('academic_unit_closure', function (Blueprint $table) {
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ancestor_id')->constrained('academic_units')->cascadeOnDelete();
            $table->foreignId('descendant_id')->constrained('academic_units')->cascadeOnDelete();
            $table->unsignedInteger('depth');
            $table->primary(['ancestor_id', 'descendant_id']);
            $table->index(['organization_id', 'descendant_id', 'depth']);
        });

        Schema::create('academic_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_period_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->unsignedSmallInteger('starts_at_minute');
            $table->unsignedSmallInteger('ends_at_minute');
            $table->timestamps();
            $table->unique(['organization_id', 'academic_period_id', 'weekday']);
        });

        Schema::create('calendar_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_period_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('kind', 24);
            $table->string('name');
            $table->unsignedSmallInteger('starts_at_minute')->nullable();
            $table->unsignedSmallInteger('ends_at_minute')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'academic_period_id', 'date']);
        });

        Schema::create('scheduling_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('type', 24);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'type', 'is_active']);
        });

        Schema::create('student_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduling_resource_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_unit_id')->constrained()->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedInteger('expected_headcount')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'academic_year_id', 'code']);
            $table->unique(['organization_id', 'id']);
        });

        Schema::create('student_group_periods', function (Blueprint $table) {
            $table->foreignId('student_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_period_id')->constrained()->cascadeOnDelete();
            $table->primary(['student_group_id', 'academic_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_group_periods');
        Schema::dropIfExists('student_groups');
        Schema::dropIfExists('scheduling_resources');
        Schema::dropIfExists('calendar_exceptions');
        Schema::dropIfExists('academic_calendars');
        Schema::dropIfExists('academic_unit_closure');
        Schema::table('membership_role_assignments', fn (Blueprint $table) => $table->dropForeign('membership_role_unit_tenant_fk'));
        Schema::dropIfExists('academic_units');
        Schema::dropIfExists('academic_unit_type_edges');
        Schema::dropIfExists('academic_unit_types');
        Schema::dropIfExists('academic_periods');
        Schema::dropIfExists('academic_years');
    }
};
