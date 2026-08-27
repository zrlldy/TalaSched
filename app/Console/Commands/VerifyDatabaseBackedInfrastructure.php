<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

#[Signature('infrastructure:verify-database-drivers')]
#[Description('Verify the database-backed cache, session, queue, and lock development baseline.')]
class VerifyDatabaseBackedInfrastructure extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $checks = [];

        $this->checkConfiguration($checks);
        $this->checkTables($checks);
        $this->checkDatabaseCache($checks);
        $this->checkDatabaseLocks($checks);

        $this->renderChecks($checks);

        return collect($checks)->contains(fn (array $check): bool => ! $check['passed'])
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param  array<int, array{name: string, passed: bool, detail: string}>  $checks
     */
    private function checkConfiguration(array &$checks): void
    {
        $this->addCheck(
            $checks,
            'Database cache configured',
            config('cache.default') === 'database',
            'CACHE_STORE='.config('cache.default'),
        );
        $this->addCheck(
            $checks,
            'Database rate limiter configured',
            config('cache.limiter') === 'database',
            'CACHE_LIMITER='.config('cache.limiter'),
        );
        $this->addCheck(
            $checks,
            'Database sessions configured',
            config('session.driver') === 'database',
            'SESSION_DRIVER='.config('session.driver'),
        );
        $this->addCheck(
            $checks,
            'Database queue configured',
            config('queue.default') === 'database',
            'QUEUE_CONNECTION='.config('queue.default'),
        );
    }

    /**
     * @param  array<int, array{name: string, passed: bool, detail: string}>  $checks
     */
    private function checkTables(array &$checks): void
    {
        $tables = [
            'cache',
            'cache_locks',
            'sessions',
            'jobs',
            'job_batches',
            'failed_jobs',
        ];

        foreach ($tables as $table) {
            $this->addCheck(
                $checks,
                'Table exists: '.$table,
                Schema::hasTable($table),
                'table='.$table,
            );
        }
    }

    /**
     * @param  array<int, array{name: string, passed: bool, detail: string}>  $checks
     */
    private function checkDatabaseCache(array &$checks): void
    {
        $key = 'infrastructure:database-cache:'.(string) Str::uuid();

        try {
            Cache::store('database')->put($key, 'ok', 60);
            $value = Cache::store('database')->get($key);
            Cache::store('database')->forget($key);

            $this->addCheck($checks, 'Database cache round trip', $value === 'ok', 'cache='.(string) $value);
        } catch (Throwable $exception) {
            $this->addCheck($checks, 'Database cache round trip', false, $exception->getMessage());
        }
    }

    /**
     * @param  array<int, array{name: string, passed: bool, detail: string}>  $checks
     */
    private function checkDatabaseLocks(array &$checks): void
    {
        $key = 'infrastructure:database-lock:'.(string) Str::uuid();

        try {
            $store = Cache::store('database')->getStore();

            if (! $store instanceof LockProvider) {
                throw new LogicException('The database cache store does not provide cache locks.');
            }

            $lock = $store->lock($key, 10);
            $acquired = $lock->get();

            if ($acquired) {
                $lock->release();
            }

            $this->addCheck($checks, 'Database cache lock', $acquired, 'lock='.($acquired ? 'acquired' : 'unavailable'));
        } catch (Throwable $exception) {
            $this->addCheck($checks, 'Database cache lock', false, $exception->getMessage());
        }
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
}
