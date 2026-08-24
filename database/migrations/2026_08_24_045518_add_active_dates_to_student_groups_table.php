<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_groups', function (Blueprint $table): void {
            $table->date('active_from')->nullable()->after('name');
            $table->date('active_until')->nullable()->after('active_from');
            $table->index(['organization_id', 'academic_year_id', 'active_from', 'active_until'], 'student_group_active_dates_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('student_groups', function (Blueprint $table): void {
            $table->dropIndex('student_group_active_dates_lookup');
            $table->dropColumn(['active_from', 'active_until']);
        });
    }
};
