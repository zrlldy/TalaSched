<?php

use App\Audit\AuditLogger;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

test('production database verification passes', function () {
    $serverVersionNumber = (int) DB::scalar("select current_setting('server_version_num')");
    $serverMajorVersion = intdiv($serverVersionNumber, 10000);
    $expectedMajorVersion = (int) config('database.production.postgresql_major_version');

    if ($serverMajorVersion !== $expectedMajorVersion) {
        $this->artisan('database:verify-production')->assertFailed();
        config(['database.production.postgresql_major_version' => $serverMajorVersion]);
    }

    try {
        $this->artisan('database:verify-production')->assertSuccessful();
    } finally {
        config(['database.production.postgresql_major_version' => $expectedMajorVersion]);
    }
});

test('the application role is neither privileged nor an owner of protected tables', function () {
    $role = DB::selectOne(<<<'SQL'
        SELECT roles.rolsuper, roles.rolbypassrls
        FROM pg_roles AS roles
        WHERE roles.rolname = current_user
        SQL);

    $ownedProtectedTables = DB::scalar(<<<'SQL'
        SELECT count(*)
        FROM pg_class AS tables
        JOIN pg_roles AS owners ON owners.oid = tables.relowner
        WHERE tables.relkind = 'r'
          AND tables.relrowsecurity
          AND owners.rolname = current_user
        SQL);

    $protectedTableOwners = collect(DB::select(<<<'SQL'
        SELECT DISTINCT owners.rolname
        FROM pg_class AS tables
        JOIN pg_roles AS owners ON owners.oid = tables.relowner
        WHERE tables.relkind = 'r'
          AND tables.relrowsecurity
        ORDER BY owners.rolname
        SQL))->pluck('rolname')->all();

    $databaseRoles = collect(DB::select(<<<'SQL'
        SELECT roles.rolname, roles.rolcanlogin, roles.rolbypassrls
        FROM pg_roles AS roles
        WHERE roles.rolname IN ('talasched_app', 'talasched_migrator', 'talasched_owner')
        ORDER BY roles.rolname
        SQL))->keyBy('rolname');

    expect($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse()
        ->and((int) $ownedProtectedTables)->toBe(0)
        ->and($protectedTableOwners)->toBe(['talasched_owner'])
        ->and($databaseRoles['talasched_app']->rolbypassrls)->toBeFalse()
        ->and($databaseRoles['talasched_migrator']->rolbypassrls)->toBeTrue()
        ->and($databaseRoles['talasched_owner']->rolbypassrls)->toBeFalse()
        ->and($databaseRoles['talasched_owner']->rolcanlogin)->toBeFalse();
});

test('every domain tenant table has a forced fail-closed policy', function () {
    $controlPlaneTables = ['organization_invitations', 'organization_members'];

    $tenantTables = collect(DB::select(<<<'SQL'
        SELECT tables.relname AS table_name,
               tables.relrowsecurity AS rls_enabled,
               tables.relforcerowsecurity AS rls_forced
        FROM pg_class AS tables
        JOIN pg_namespace AS schemas ON schemas.oid = tables.relnamespace
        JOIN pg_attribute AS columns ON columns.attrelid = tables.oid
        WHERE schemas.nspname = current_schema()
          AND tables.relkind = 'r'
          AND columns.attname = 'organization_id'
          AND NOT columns.attisdropped
        ORDER BY tables.relname
        SQL));

    $unprotectedTables = $tenantTables
        ->reject(fn (object $table): bool => in_array($table->table_name, $controlPlaneTables, true))
        ->filter(fn (object $table): bool => ! $table->rls_enabled || ! $table->rls_forced)
        ->pluck('table_name')
        ->all();

    $unexpectedControlPlanePolicies = $tenantTables
        ->filter(fn (object $table): bool => in_array($table->table_name, $controlPlaneTables, true))
        ->filter(fn (object $table): bool => $table->rls_enabled || $table->rls_forced)
        ->pluck('table_name')
        ->all();

    $protectedTables = $tenantTables
        ->reject(fn (object $table): bool => in_array($table->table_name, $controlPlaneTables, true))
        ->pluck('table_name')
        ->all();

    $policyTables = collect(DB::select(<<<'SQL'
        SELECT tablename AS table_name
        FROM pg_policies
        WHERE schemaname = current_schema()
          AND policyname = 'tenant_isolation'
          AND qual IS NOT NULL
          AND with_check IS NOT NULL
        ORDER BY tablename
        SQL))
        ->pluck('table_name')
        ->all();

    expect($unprotectedTables)->toBe([])
        ->and($unexpectedControlPlanePolicies)->toBe([])
        ->and($policyTables)->toBe($protectedTables);
});

test('row policies deny missing and cross-organization context', function () {
    $firstOrganization = Organization::factory()->create();
    $secondOrganization = Organization::factory()->create();
    $tenantContext = app(TenantContext::class);

    $firstYear = $tenantContext->run(
        $firstOrganization,
        fn (): AcademicYear => AcademicYear::factory()->create(['organization_id' => $firstOrganization->id]),
    );
    $secondYear = $tenantContext->run(
        $secondOrganization,
        fn (): AcademicYear => AcademicYear::factory()->create(['organization_id' => $secondOrganization->id]),
    );

    expect(AcademicYear::query()->whereKey($firstYear)->exists())->toBeFalse()
        ->and(AcademicYear::query()->whereKey($secondYear)->exists())->toBeFalse();

    $tenantContext->run($firstOrganization, function () use ($firstOrganization, $firstYear, $secondOrganization, $secondYear): void {
        expect(AcademicYear::query()->whereKey($firstYear)->exists())->toBeTrue()
            ->and(AcademicYear::query()->whereKey($secondYear)->exists())->toBeFalse();

        expect(fn () => AcademicYear::factory()->create([
            'organization_id' => $secondOrganization->id,
        ]))->toThrow(QueryException::class);

        expect(AcademicYear::query()->where('organization_id', $firstOrganization->id)->exists())->toBeTrue();
    });

    expect(AcademicYear::query()->whereKey($firstYear)->exists())->toBeFalse();
});

test('forced row security also constrains the table-owning role', function () {
    $organization = Organization::factory()->create();
    $year = app(TenantContext::class)->run(
        $organization,
        fn (): AcademicYear => AcademicYear::factory()->create(['organization_id' => $organization->id]),
    );
    $migrator = DB::connection('pgsql_migrator');

    try {
        $migrator->statement('set role talasched_owner');
        $migrator->statement("select set_config('app.current_organization_id', '', false)");

        expect($migrator->scalar('select current_user'))->toBe('talasched_owner')
            ->and((bool) $migrator->table('academic_years')->where('id', $year->id)->exists())->toBeFalse();

        $migrator->statement("select set_config('app.current_organization_id', ?, false)", [(string) $organization->id]);

        expect((bool) $migrator->table('academic_years')->where('id', $year->id)->exists())->toBeTrue();
    } finally {
        $migrator->statement("select set_config('app.current_organization_id', '', false)");
        $migrator->statement('reset role');
    }
});

test('audit events cannot be updated or deleted under an authorized tenant context', function () {
    $organization = Organization::factory()->create();
    $tenantContext = app(TenantContext::class);

    $auditEventId = $tenantContext->run($organization, function () use ($organization): int {
        app(AuditLogger::class)->record(
            action: 'audit.immutability_verified',
            organization: $organization,
            subject: $organization,
        );

        return (int) DB::table('audit_events')
            ->where('action', 'audit.immutability_verified')
            ->value('id');
    });

    expect(fn () => $tenantContext->run($organization, fn () => DB::table('audit_events')
        ->where('id', $auditEventId)
        ->update(['action' => 'audit.mutated'])))
        ->toThrow(QueryException::class);
    expect(fn () => $tenantContext->run($organization, fn () => DB::table('audit_events')
        ->where('id', $auditEventId)
        ->delete()))
        ->toThrow(QueryException::class);

    expect($tenantContext->run($organization, fn () => DB::table('audit_events')
        ->where('id', $auditEventId)
        ->value('action')))
        ->toBe('audit.immutability_verified');
});

test('global audit events require the scoped audit context and remain hidden from tenant queries', function () {
    $user = User::factory()->create();

    expect(fn () => DB::table('audit_events')->insert([
        'organization_id' => null,
        'actor_user_id' => $user->id,
        'impersonator_user_id' => null,
        'correlation_id' => (string) Str::uuid(),
        'action' => 'audit.global_direct_insert',
        'subject_type' => User::class,
        'subject_id' => (string) $user->id,
        'before' => null,
        'after' => null,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TalaSched PostgreSQL test',
        'occurred_at' => now(),
    ]))->toThrow(QueryException::class);

    app(AuditLogger::class)->record(
        action: 'audit.global_insert_permitted',
        actor: $user,
        subject: $user,
    );

    expect(DB::table('audit_events')
        ->where('action', 'audit.global_insert_permitted')
        ->exists())->toBeFalse()
        ->and((bool) DB::connection('pgsql_migrator')->table('audit_events')
            ->where('action', 'audit.global_insert_permitted')
            ->where('subject_id', (string) $user->id)
            ->exists())->toBeTrue();
});

test('tenant state is cleared after callbacks and database reconnection', function () {
    $organization = Organization::factory()->create();
    $tenantContext = app(TenantContext::class);

    expect(fn () => $tenantContext->run(
        $organization,
        fn () => throw new RuntimeException('tenant callback failed'),
    ))->toThrow(RuntimeException::class);

    expect(DB::scalar("select current_setting('app.current_organization_id', true)"))->toBe('');

    $tenantContext->set($organization);
    expect(DB::scalar("select current_setting('app.current_organization_id', true)"))->toBe((string) $organization->id);

    DB::disconnect();
    DB::reconnect();

    expect(DB::scalar("select current_setting('app.current_organization_id', true)"))->toBeNull();

    $tenantContext->clear();
});
