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
        Schema::table('export_runs', function (Blueprint $table): void {
            $table->string('purpose', 24)->default('export')->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('export_runs', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
    }
};
