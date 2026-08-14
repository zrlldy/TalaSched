<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->uuid('public_id')->nullable()->unique()->after('id');
            $table->foreignId('owner_user_id')->nullable()->after('slug')->constrained('users')->nullOnDelete();
            $table->string('timezone', 64)->default('Asia/Manila');
            $table->string('locale', 16)->default('en');
            $table->unsignedSmallInteger('scheduling_granularity')->default(30);
            $table->string('status', 32)->default('active');
            $table->unique(['id', 'owner_user_id']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('name');
            $table->string('module', 64);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'id']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('membership_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('organization_members')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('academic_unit_id')->nullable();
            $table->timestamps();
            $table->unique(['membership_id', 'role_id', 'academic_unit_id'], 'membership_role_scope_unique');
            $table->index(['organization_id', 'academic_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['id', 'owner_user_id']);
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropColumn(['public_id', 'timezone', 'locale', 'scheduling_granularity', 'status']);
        });
    }
};
