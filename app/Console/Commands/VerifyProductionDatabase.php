<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('database:verify-production {--connection= : Database connection to verify}')]
#[Description('Verify the production PostgreSQL baseline, role topology, and tenant isolation controls.')]
class VerifyProductionDatabase extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connectionName = $this->option('connection') ?: config('database.default');
        $connection = DB::connection(is_string($connectionName) ? $connectionName : null);
        $checks = [];

        $this->addCheck(
            $checks,
            'PostgreSQL connection',
            $connection->getDriverName() === 'pgsql',
            sprintf('connection=%s driver=%s', $connection->getName(), $connection->getDriverName()),
        );

        if ($connection->getDriverName() !== 'pgsql') {
            $this->renderChecks($checks);

            return self::FAILURE;
        }

        try {
            $this->checkServerVersion($connection, $checks);
            $this->checkSessionConfiguration($connection, $checks);
            $this->checkRuntimeRole($connection, $checks);
            $this->checkTenantProtection($connection, $checks);
            $this->checkRequiredExtensions($connection, $checks);
        } catch (Throwable $exception) {
            $this->addCheck($checks, 'Catalog verification', false, $exception->getMessage());
        }

        $this->renderChecks($checks);

        return collect($checks)->contains(fn (array $check): bool => ! $check['passed'])
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param  array<int, array{name: string, passed: bool, detail: string}>  $checks
     */
    private function checkServerVersion(Connection $connection, array &$checks): void
    {
        $serverVersionNumber = (int) $connection->scalar("select current_setting('server_version_num')");
        $actualMajorVersion = intdiv($serverVersionNumber, 10000);
        $expectedMajorVersion = (int) config('database.production.postgresql_major_version', 17);

        $this->addCheck(
            $checks,
            'PostgreSQL major version',
            $actualMajorVersion === $expectedMajorVersion,
            sprintf('expected=%d actual=%d', $expectedMajorVersion, $actualMajorVersion),
        );
    }

    /**
     * @param  array<int, array{name: string, passed: bool, detail: string}>  $checks
     */
    private function checkSessionConfiguration(Connection $connection, array &$checks): void
    {
        $expectedTimezone = (string) config('database.production.timezone', 'UTC');
        $timezone = (string) $connection->scalar("select current_setting('TimeZone')");
        $schema = (string) $connection->scalar('select current_schema()');
        $sslEnabled = $this->databaseBoolean($connection->scalar('select ssl from pg_stat_ssl where pid = pg_backend_pid()'));
        $requiresSsl = (bool) config('database.production.require_ssl', true);

        $this->addCheck(
            $checks,
            'Session timezone',
            $timezone === $expectedTimezone,
            sprintf('expected=%s actual=%s', $expectedTimezone, $timezone),
        );
        $this->addCheck(
            $checks,
            'Current schema',
            $schema === 'public',
            sprintf('expected=public actual=%s', $schema),
        );
        $this->addCheck(
            $checks,
            'TLS in use',
            ! $requiresSsl || $sslEnabled,
            sprintf('required=%s active=%s', $requiresSsl ? 'yes' : 'no', $sslEnabled ? 'yes' : 'no'),
        );
    }

    /**
     * @param  array<int, array{name: string, passed: bool, detail: string}>  $checks
     */
    private function checkRuntimeRole(Connection $connection, array &$checks): void
    {
        $roleRow = $connection->selectOne(<<<'SQL'
            SELECT roles.rolname, roles.rolsuper, roles.rolbypassrls, roles.rolcanlogin
            FROM pg_roles AS roles
            WHERE roles.rolname = current_user
            SQL);
        $role = is_object($roleRow) ? get_object_vars($roleRow) : [];
        $roleName = is_string($role['rolname'] ?? null) ? $role['rolname'] : 'unknown';
        $roleIsSuperuser = $this->databaseBoolean($role['rolsuper'] ?? null);
        $roleBypassesRls = $this->databaseBoolean($role['rolbypassrls'] ?? null);
        $roleCanLogin = $this->databaseBoolean($role['rolcanlogin'] ?? null);

        $ownedProtectedTables = (int) $connection->scalar(<<<'SQL'
            SELECT count(*)
            FROM pg_class AS tables
            JOIN pg_roles AS owners ON owners.oid = tables.relowner
            WHERE tables.relkind = 'r'
              AND tables.relrowsecurity
              AND owners.rolname = current_user
            SQL);

        $runtimeRoleIsRestricted = $role !== []
            && ! $roleIsSuperuser
            && ! $roleBypassesRls
            && $roleCanLogin;

        $this->addCheck(
            $checks,
            'Runtime role privileges',
            $runtimeRoleIsRestricted,
            sprintf(
                'role=%s superuser=%s bypass_rls=%s login=%s',
                $roleName,
                $roleIsSuperuser ? 'yes' : 'no',
                $roleBypassesRls ? 'yes' : 'no',
                $roleCanLogin ? 'yes' : 'no',
            ),
        );
        $this->addCheck(
            $checks,
            'Runtime role table ownership',
            $ownedProtectedTables === 0,
            sprintf('protected_tables_owned=%d', $ownedProtectedTables),
        );
    }

    /**
     * @param  array<int, array{name: string, passed: bool, detail: string}>  $checks
     */
    private function checkTenantProtection(Connection $connection, array &$checks): void
    {
        $controlPlaneTables = config('database.production.rls_exempt_tables', []);
        $tenantTables = $this->tenantTables($connection);
        $protectedTables = $tenantTables
            ->reject(fn (array $table): bool => in_array($table['table_name'], $controlPlaneTables, true));

        $unprotectedTables = $protectedTables
            ->filter(fn (array $table): bool => ! $this->databaseBoolean($table['rls_enabled']) || ! $this->databaseBoolean($table['rls_forced']))
            ->pluck('table_name')
            ->all();

        $unexpectedControlPlanePolicies = $tenantTables
            ->filter(fn (array $table): bool => in_array($table['table_name'], $controlPlaneTables, true))
            ->filter(fn (array $table): bool => $this->databaseBoolean($table['rls_enabled']) || $this->databaseBoolean($table['rls_forced']))
            ->pluck('table_name')
            ->all();

        $expectedPolicyTables = $protectedTables->pluck('table_name')->all();
        $actualPolicyTables = collect($connection->select(<<<'SQL'
            SELECT tablename AS table_name
            FROM pg_policies
            WHERE schemaname = current_schema()
              AND policyname = 'tenant_isolation'
              AND qual IS NOT NULL
              AND with_check IS NOT NULL
            ORDER BY tablename
            SQL))
            ->map(function (object $policy): string {
                $attributes = get_object_vars($policy);

                return (string) ($attributes['table_name'] ?? '');
            })
            ->all();

        $owners = collect($connection->select(<<<'SQL'
            SELECT DISTINCT owners.rolname, owners.rolcanlogin, owners.rolbypassrls
            FROM pg_class AS tables
            JOIN pg_roles AS owners ON owners.oid = tables.relowner
            WHERE tables.relkind = 'r'
              AND tables.relrowsecurity
            ORDER BY owners.rolname
            SQL))
            ->map(function (object $owner): array {
                $attributes = get_object_vars($owner);

                return [
                    'rolname' => (string) ($attributes['rolname'] ?? ''),
                    'rolcanlogin' => $attributes['rolcanlogin'] ?? null,
                    'rolbypassrls' => $attributes['rolbypassrls'] ?? null,
                ];
            });

        $ownerRolesAreRestricted = $owners->isNotEmpty()
            && $owners->every(fn (array $owner): bool => ! $this->databaseBoolean($owner['rolcanlogin']) && ! $this->databaseBoolean($owner['rolbypassrls']));

        $this->addCheck(
            $checks,
            'Forced tenant RLS',
            $unprotectedTables === [] && $unexpectedControlPlanePolicies === [],
            sprintf(
                'protected=%d unprotected=%s control_plane_with_rls=%s',
                count($expectedPolicyTables),
                $this->formatList($unprotectedTables),
                $this->formatList($unexpectedControlPlanePolicies),
            ),
        );
        $this->addCheck(
            $checks,
            'Tenant isolation policies',
            $actualPolicyTables === $expectedPolicyTables,
            sprintf('expected=%d actual=%d', count($expectedPolicyTables), count($actualPolicyTables)),
        );
        $this->addCheck(
            $checks,
            'Protected table owners',
            $ownerRolesAreRestricted,
            'owners='.$this->formatList($owners->pluck('rolname')->all()),
        );
    }

    /**
     * @param  array<int, array{name: string, passed: bool, detail: string}>  $checks
     */
    private function checkRequiredExtensions(Connection $connection, array &$checks): void
    {
        $btreeGistInstalled = $this->databaseBoolean($connection->scalar(<<<'SQL'
            SELECT exists (
                SELECT 1
                FROM pg_extension
                WHERE extname = 'btree_gist'
            )
            SQL));

        $this->addCheck(
            $checks,
            'Required PostgreSQL extensions',
            $btreeGistInstalled,
            'btree_gist='.($btreeGistInstalled ? 'installed' : 'missing'),
        );
    }

    /**
     * @return Collection<int, array{table_name: string, rls_enabled: mixed, rls_forced: mixed}>
     */
    private function tenantTables(Connection $connection): Collection
    {
        return collect($connection->select(<<<'SQL'
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
            SQL))
            ->map(function (object $table): array {
                $attributes = get_object_vars($table);

                return [
                    'table_name' => (string) ($attributes['table_name'] ?? ''),
                    'rls_enabled' => $attributes['rls_enabled'] ?? null,
                    'rls_forced' => $attributes['rls_forced'] ?? null,
                ];
            });
    }

    /**
     * @param  array<int, array{name: string, passed: bool, detail: string}>  $checks
     */
    private function addCheck(array &$checks, string $name, bool $passed, string $detail): void
    {
        $checks[] = [
            'name' => $name,
            'passed' => $passed,
            'detail' => $detail,
        ];
    }

    /**
     * @param  array<int, array{name: string, passed: bool, detail: string}>  $checks
     */
    private function renderChecks(array $checks): void
    {
        $this->table(
            ['Check', 'Status', 'Detail'],
            collect($checks)
                ->map(fn (array $check): array => [
                    $check['name'],
                    $check['passed'] ? 'PASS' : 'FAIL',
                    $check['detail'],
                ])
                ->all(),
        );
    }

    private function databaseBoolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    /**
     * @param  array<int, string>  $values
     */
    private function formatList(array $values): string
    {
        return $values === [] ? 'none' : implode(',', $values);
    }
}
