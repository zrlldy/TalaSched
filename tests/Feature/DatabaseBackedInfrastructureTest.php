<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('required database-backed infrastructure tables exist', function () {
    expect(Schema::hasTable('cache'))->toBeTrue()
        ->and(Schema::hasTable('cache_locks'))->toBeTrue()
        ->and(Schema::hasTable('sessions'))->toBeTrue()
        ->and(Schema::hasTable('jobs'))->toBeTrue()
        ->and(Schema::hasTable('job_batches'))->toBeTrue()
        ->and(Schema::hasTable('failed_jobs'))->toBeTrue();
});

test('database-backed infrastructure configuration is selected for development', function () {
    expect(config('cache.default'))->toBe('database')
        ->and(config('cache.limiter'))->toBe('database')
        ->and(config('session.driver'))->toBe('database')
        ->and(config('queue.default'))->toBe('database');
});

test('database cache and cache locks work', function () {
    $cacheKey = 'tests:database-cache:'.(string) Str::uuid();
    $lockKey = 'tests:database-lock:'.(string) Str::uuid();

    Cache::store('database')->put($cacheKey, 'ok', 60);

    $lock = Cache::store('database')->lock($lockKey, 10);
    $acquired = $lock->get();

    try {
        expect(Cache::store('database')->get($cacheKey))->toBe('ok')
            ->and($acquired)->toBeTrue()
            ->and(DB::table('cache')->where('key', config('cache.prefix').$cacheKey)->exists())->toBeTrue()
            ->and(DB::table('cache_locks')->where('key', config('cache.prefix').$lockKey)->exists())->toBeTrue();
    } finally {
        if ($acquired) {
            $lock->release();
        }

        Cache::store('database')->forget($cacheKey);
    }
});

test('database queue stores queued jobs', function () {
    $jobId = Queue::connection('database')->push(new DatabaseBackedInfrastructureProbeJob);

    expect($jobId)->not->toBeFalse()
        ->and(DB::table('jobs')->where('id', $jobId)->where('queue', 'default')->exists())->toBeTrue();
});

test('database-backed infrastructure verifier passes', function () {
    $this->artisan('infrastructure:verify-database-drivers')->assertSuccessful();
});

final class DatabaseBackedInfrastructureProbeJob implements ShouldQueue
{
    public function handle(): void {}
}
