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
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['owner_user_id']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->uuid('public_id')->nullable(false)->change();
            $table->unsignedBigInteger('owner_user_id')->nullable(false)->change();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->foreign('owner_user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['owner_user_id']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->uuid('public_id')->nullable()->change();
            $table->unsignedBigInteger('owner_user_id')->nullable()->change();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
