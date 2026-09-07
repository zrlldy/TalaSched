<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('excel_template_versions', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->after('organization_id');
        });

        DB::table('excel_template_versions')
            ->whereNull('public_id')
            ->orderBy('id')
            ->eachById(function (object $version): void {
                DB::table('excel_template_versions')
                    ->where('id', $version->id)
                    ->update(['public_id' => (string) Str::uuid()]);
            });

        Schema::table('excel_template_versions', function (Blueprint $table): void {
            $table->unique('public_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('excel_template_versions', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
