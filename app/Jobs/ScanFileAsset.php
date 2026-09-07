<?php

namespace App\Jobs;

use App\Actions\ScanFileAsset as ScanFileAssetAction;
use App\Jobs\Middleware\UseTenantContext;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use LogicException;
use Throwable;

class ScanFileAsset implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    public int $uniqueFor = 600;

    /** @var list<int> */
    public array $backoff = [15, 60];

    public function __construct(
        public string $organizationPublicId,
        public string $fileAssetPublicId,
    ) {}

    public function handle(ScanFileAssetAction $scanner): void
    {
        $organization = Container::getInstance()->make(TenantContext::class)->organization();

        if (! $organization instanceof Organization) {
            throw new LogicException('A tenant context is required to scan a file asset.');
        }

        $scanner->handle($organization, $this->fileAssetPublicId);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new UseTenantContext($this->organizationPublicId)];
    }

    public function uniqueId(): string
    {
        return 'file-asset-scan:'.$this->fileAssetPublicId;
    }

    public function failed(?Throwable $exception): void
    {
        $tenantContext = Container::getInstance()->make(TenantContext::class);

        $tenantContext->runByPublicId(
            $this->organizationPublicId,
            function (Organization $organization) use ($exception): void {
                Container::getInstance()->make(ScanFileAssetAction::class)->fail(
                    $organization,
                    $this->fileAssetPublicId,
                    $exception,
                );
            },
        );
    }
}
