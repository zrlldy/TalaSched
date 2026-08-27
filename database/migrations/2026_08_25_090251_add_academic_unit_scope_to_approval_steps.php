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
        Schema::table('approval_workflow_steps', function (Blueprint $table): void {
            $table->unsignedBigInteger('academic_unit_id')->nullable()->after('approval_workflow_version_id');
            $table->index(['organization_id', 'academic_unit_id'], 'approval_workflow_step_unit_lookup');
            $table->foreign(['organization_id', 'academic_unit_id'], 'approval_workflow_step_unit_tenant_fk')
                ->references(['organization_id', 'id'])
                ->on('academic_units')
                ->restrictOnDelete();
        });

        Schema::table('approval_instance_steps', function (Blueprint $table): void {
            $table->unsignedBigInteger('academic_unit_id')->nullable()->after('approval_instance_id');
            $table->index(['organization_id', 'academic_unit_id'], 'approval_instance_step_unit_lookup');
            $table->foreign(['organization_id', 'academic_unit_id'], 'approval_instance_step_unit_tenant_fk')
                ->references(['organization_id', 'id'])
                ->on('academic_units')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('approval_instance_steps', function (Blueprint $table): void {
            $table->dropForeign('approval_instance_step_unit_tenant_fk');
            $table->dropIndex('approval_instance_step_unit_lookup');
            $table->dropColumn('academic_unit_id');
        });

        Schema::table('approval_workflow_steps', function (Blueprint $table): void {
            $table->dropForeign('approval_workflow_step_unit_tenant_fk');
            $table->dropIndex('approval_workflow_step_unit_lookup');
            $table->dropColumn('academic_unit_id');
        });
    }
};
