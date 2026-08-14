<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('approval_workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['approval_workflow_id', 'version_number']);
        });

        Schema::create('approval_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_workflow_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('label');
            $table->string('approver_selector_type', 32);
            $table->string('required_permission', 120)->nullable();
            $table->unsignedSmallInteger('minimum_approvals')->default(1);
            $table->boolean('allow_self_approval')->default(false);
            $table->string('signatory_slot', 64)->nullable();
            $table->timestamps();
            $table->unique(['approval_workflow_version_id', 'sequence']);
        });

        Schema::create('approval_step_roles', function (Blueprint $table) {
            $table->foreignId('approval_workflow_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['approval_workflow_step_id', 'role_id']);
        });

        Schema::create('signatory_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('academic_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->string('position');
            $table->string('academic_unit_name')->nullable();
            $table->string('signature_disk')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('signature_checksum', 64)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('approval_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timetable_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_workflow_version_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('pending');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique('timetable_version_id');
            $table->index(['organization_id', 'status']);
        });

        Schema::create('approval_instance_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_instance_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('label');
            $table->unsignedSmallInteger('minimum_approvals')->default(1);
            $table->boolean('allow_self_approval')->default(false);
            $table->string('status', 32)->default('pending');
            $table->string('signatory_slot', 64)->nullable();
            $table->timestamps();
            $table->unique(['approval_instance_id', 'sequence']);
        });

        Schema::create('approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_instance_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 32);
            $table->text('comment')->nullable();
            $table->string('signatory_name')->nullable();
            $table->string('signatory_position')->nullable();
            $table->string('signatory_academic_unit')->nullable();
            $table->string('signature_disk')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('signature_checksum', 64)->nullable();
            $table->timestamp('acted_at');
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('capabilities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('name');
            $table->string('value_type', 16);
            $table->timestamps();
        });

        Schema::create('plan_capability_values', function (Blueprint $table) {
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('capability_id')->constrained()->cascadeOnDelete();
            $table->boolean('boolean_value')->nullable();
            $table->unsignedBigInteger('integer_value')->nullable();
            $table->primary(['plan_id', 'capability_id']);
        });

        Schema::create('organization_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 32);
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('organization_entitlement_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('capability_id')->constrained()->cascadeOnDelete();
            $table->boolean('boolean_value')->nullable();
            $table->unsignedBigInteger('integer_value')->nullable();
            $table->string('reason');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'capability_id']);
        });

        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('capability_id')->constrained()->cascadeOnDelete();
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->unsignedBigInteger('quantity')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'capability_id', 'period_starts_on']);
        });

        Schema::create('file_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->string('scan_status', 24)->default('pending');
            $table->timestamps();
            $table->unique(['organization_id', 'disk', 'path']);
        });

        Schema::create('excel_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->string('status', 24)->default('draft');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('excel_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('excel_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_asset_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->unsignedSmallInteger('mapping_schema_version')->default(1);
            $table->json('mapping');
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['excel_template_id', 'version_number']);
        });

        Schema::create('export_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timetable_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('excel_template_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('artifact_file_asset_id')->nullable()->constrained('file_assets')->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('status', 24)->default('pending');
            $table->string('input_hash', 64);
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('impersonator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id');
            $table->string('action', 120);
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('export_runs');
        Schema::dropIfExists('excel_template_versions');
        Schema::dropIfExists('excel_templates');
        Schema::dropIfExists('file_assets');
        Schema::dropIfExists('usage_counters');
        Schema::dropIfExists('organization_entitlement_overrides');
        Schema::dropIfExists('organization_subscriptions');
        Schema::dropIfExists('plan_capability_values');
        Schema::dropIfExists('capabilities');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('approval_actions');
        Schema::dropIfExists('approval_instance_steps');
        Schema::dropIfExists('approval_instances');
        Schema::dropIfExists('signatory_profiles');
        Schema::dropIfExists('approval_step_roles');
        Schema::dropIfExists('approval_workflow_steps');
        Schema::dropIfExists('approval_workflow_versions');
        Schema::dropIfExists('approval_workflows');
    }
};
