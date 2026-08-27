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
        Schema::table('approval_workflow_versions', function (Blueprint $table): void {
            $table->boolean('require_distinct_approvers')->default(false)->after('approval_workflow_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('approval_workflow_versions', function (Blueprint $table): void {
            $table->dropColumn('require_distinct_approvers');
        });
    }
};
