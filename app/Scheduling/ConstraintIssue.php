<?php

namespace App\Scheduling;

use App\Enums\ConstraintSeverity;

final readonly class ConstraintIssue
{
    /**
     * @param  array{id: string|null, name: string|null}|null  $resource
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $code,
        public ConstraintSeverity $severity,
        public string $field,
        public string $message,
        public ?array $resource = null,
        public ?string $conflictingEntryId = null,
        public array $details = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'field' => $this->field,
            'rule_code' => $this->code,
            'message' => $this->message,
            'details' => $this->details,
            'resource' => $this->resource,
            'conflicting_entry_id' => $this->conflictingEntryId,
        ];
    }

    public function withSeverity(ConstraintSeverity $severity): self
    {
        return new self(
            code: $this->code,
            severity: $severity,
            field: $this->field,
            message: $this->message,
            resource: $this->resource,
            conflictingEntryId: $this->conflictingEntryId,
            details: $this->details,
        );
    }
}
