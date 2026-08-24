<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->after('id');
            $table->string('token_hash', 64)->nullable()->after('public_id');
            $table->string('email_normalized')->nullable()->after('email');
        });

        DB::table('organization_invitations')
            ->select(['id', 'code', 'email'])
            ->orderBy('id')
            ->eachById(function (object $invitation): void {
                DB::table('organization_invitations')
                    ->where('id', $invitation->id)
                    ->update([
                        'public_id' => (string) Str::uuid(),
                        'token_hash' => hash('sha256', $invitation->code),
                        'email_normalized' => mb_strtolower(trim($invitation->email), 'UTF-8'),
                    ]);
            });

        Schema::table('organization_invitations', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable(false)->change();
            $table->string('token_hash', 64)->nullable(false)->change();
            $table->string('email_normalized')->nullable(false)->change();
            $table->dropUnique('organization_invitations_code_unique');
            $table->dropColumn('code');
            $table->unique('public_id');
            $table->unique('token_hash');
            $table->index(['organization_id', 'email_normalized']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE organization_invitations ADD CONSTRAINT organization_invitations_expiry_after_creation CHECK (expires_at IS NULL OR expires_at > created_at)');
            DB::statement('ALTER TABLE organization_invitations ADD CONSTRAINT organization_invitations_acceptance_after_creation CHECK (accepted_at IS NULL OR accepted_at >= created_at)');
            DB::statement('ALTER TABLE organization_invitations ADD CONSTRAINT organization_invitations_acceptance_before_expiry CHECK (accepted_at IS NULL OR expires_at IS NULL OR accepted_at <= expires_at)');
        }
    }

    public function down(): void
    {
        throw new LogicException('Invitation token plaintext cannot be restored after hashing. Apply a forward migration instead.');
    }
};
