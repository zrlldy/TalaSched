<?php

namespace App\Jobs;

use App\Actions\RenderTemplateExport;
use App\Jobs\Middleware\UseTenantContext;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class GenerateTemplateExport implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 60;

    public int $tries = 2;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [5];

    public function __construct(
        public string $organizationPublicId,
        public string $exportRunPublicId,
    ) {}

    public function handle(RenderTemplateExport $renderer): void
    {
        try {
            $renderer->handle($this->exportRunPublicId);
        } catch (Throwable $exception) {
            $renderer->fail($this->exportRunPublicId, $exception);

            throw $exception;
        }
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new RateLimited('template-export-generation'),
            new UseTenantContext($this->organizationPublicId),
        ];
    }

    public function uniqueId(): string
    {
        return 'template-export:'.$this->exportRunPublicId;
    }

    public function failed(?Throwable $exception): void
    {
        $tenantContext = Container::getInstance()->make(TenantContext::class);

        $tenantContext->runByPublicId(
            $this->organizationPublicId,
            function () use ($exception): void {
                Container::getInstance()
                    ->make(RenderTemplateExport::class)
                    ->fail($this->exportRunPublicId, $exception);
            },
        );
    }
}
