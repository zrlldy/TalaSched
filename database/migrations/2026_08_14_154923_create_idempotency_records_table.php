<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('route', 160);
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->string('status', 24)->default('processing');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(
                ['organization_id', 'actor_user_id', 'route', 'key_hash'],
                'idempotency_record_scope_unique',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE idempotency_records ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE idempotency_records FORCE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY tenant_isolation ON idempotency_records USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::bigint) WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::bigint)");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
