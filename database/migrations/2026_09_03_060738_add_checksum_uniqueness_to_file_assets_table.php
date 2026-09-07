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
        Schema::table('file_assets', function (Blueprint $table): void {
            $table->unique(
                ['organization_id', 'disk', 'checksum'],
                'file_assets_organization_disk_checksum_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('file_assets', function (Blueprint $table): void {
            $table->dropUnique('file_assets_organization_disk_checksum_unique');
        });
    }
};
