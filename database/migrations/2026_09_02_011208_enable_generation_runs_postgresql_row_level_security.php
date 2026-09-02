<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE generation_runs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE generation_runs FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation ON generation_runs USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::bigint) WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::bigint)");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON generation_runs');
        DB::statement('ALTER TABLE generation_runs DISABLE ROW LEVEL SECURITY');
    }
};
