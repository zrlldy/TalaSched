<?php

namespace App\Scheduling\Data;

use App\Enums\GenerationStatus;

final readonly class GenerationProgress
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $runPublicId,
        public GenerationStatus $status,
        public ?int $percent = null,
        public ?string $message = null,
        public array $details = [],
    ) {}

    /**
     * @return array{run_id: string, status: string, percent: int|null, message: string|null, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runPublicId,
            'status' => $this->status->value,
            'percent' => $this->percent,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}
