<?php

use App\Models\IdempotencyRecord;
use App\Tenancy\TenantContext;

test('expired idempotency records are pruned under each tenant context', function () {
    $expiredRecords = IdempotencyRecord::factory()->count(2)->create([
        'expires_at' => now()->subMinute(),
    ]);
    $activeRecord = IdempotencyRecord::factory()->create([
        'expires_at' => now()->addHour(),
    ]);

    $this->artisan('idempotency:prune')->assertSuccessful();

    $expiredRecords->each(fn (IdempotencyRecord $record) => $this->assertModelMissing($record));
    $this->assertModelExists($activeRecord);
    expect(app(TenantContext::class)->organization())->toBeNull();
});
