<?php

use App\Actions\ScanFileAsset;
use App\Contracts\MalwareScanner;
use App\Enums\MalwareScanResult;
use App\Jobs\Middleware\UseTenantContext;
use App\Jobs\ScanFileAsset as ScanFileAssetJob;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Models\User;
use App\Services\ClamAvMalwareScanner;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

beforeEach(function (): void {
    Storage::fake(FileAsset::PRIVATE_DISK);
    $this->owner = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->owner->currentOrganization;
});

test('a clean scan releases a pending private asset for processing', function (): void {
    $asset = pendingAsset($this->organization);
    app()->instance(MalwareScanner::class, malwareScannerReturning(MalwareScanResult::Clean));

    app(ScanFileAsset::class)->handle($this->organization, $asset->public_id);

    expect($asset->fresh()->scan_status)->toBe(FileAsset::ScanClean)
        ->and(DB::table('audit_events')->where('action', 'file_asset.scanned')->count())->toBe(1);
    Storage::disk(FileAsset::PRIVATE_DISK)->assertExists($asset->path);
});

test('an infected scan keeps an asset quarantined and unavailable to processing', function (): void {
    $asset = pendingAsset($this->organization);
    app()->instance(MalwareScanner::class, malwareScannerReturning(MalwareScanResult::Infected));

    app(ScanFileAsset::class)->handle($this->organization, $asset->public_id);

    expect($asset->fresh()->scan_status)->toBe(FileAsset::ScanQuarantined)
        ->and(DB::table('audit_events')->where('action', 'file_asset.quarantined')->count())->toBe(1);
    Storage::disk(FileAsset::PRIVATE_DISK)->assertExists($asset->path);
});

test('exhausted scanner jobs mark pending assets as failed without releasing them', function (): void {
    $asset = pendingAsset($this->organization);
    $job = new ScanFileAssetJob($this->organization->public_id, $asset->public_id);

    $job->failed(new RuntimeException('Scanner connection unavailable.'));

    expect($asset->fresh()->scan_status)->toBe(FileAsset::ScanFailed)
        ->and(DB::table('audit_events')->where('action', 'file_asset.scan_failed')->count())->toBe(1)
        ->and($job)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and(collect($job->middleware())->contains(fn (object $middleware): bool => $middleware instanceof UseTenantContext))->toBeTrue();
    Storage::disk(FileAsset::PRIVATE_DISK)->assertExists($asset->path);
});

test('the ClamAV adapter rejects invalid endpoint configuration', function (): void {
    expect(fn (): ClamAvMalwareScanner => new ClamAvMalwareScanner('127.0.0.1', 0, 30))
        ->toThrow(RuntimeException::class);
});

function pendingAsset(Organization $organization): FileAsset
{
    $path = 'organizations/'.$organization->public_id.'/workbooks/untrusted.xlsx';
    $contents = 'untrusted workbook contents';
    Storage::disk(FileAsset::PRIVATE_DISK)->put($path, $contents);

    return FileAsset::factory()->create([
        'organization_id' => $organization->getKey(),
        'path' => $path,
        'size' => strlen($contents),
        'checksum' => hash('sha256', $contents),
        'scan_status' => FileAsset::ScanPending,
    ]);
}

function malwareScannerReturning(MalwareScanResult $result): MalwareScanner
{
    return new class($result) implements MalwareScanner
    {
        public function __construct(private MalwareScanResult $result) {}

        /** @param resource $stream */
        public function scan(mixed $stream): MalwareScanResult
        {
            return $this->result;
        }
    };
}
