<?php

namespace App\Scheduling;

use App\Enums\ConstraintSeverity;

final readonly class ConstraintResult
{
    /** @param list<ConstraintIssue> $issues */
    public function __construct(
        public array $issues = [],
        public float $score = 0.0,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public static function from(ConstraintIssue ...$issues): self
    {
        return new self(array_values($issues));
    }

    public function merge(self $result): self
    {
        return new self(
            issues: [...$this->issues, ...$result->issues],
            score: $this->score + $result->score,
        );
    }

    public function withSeverity(ConstraintSeverity $severity): self
    {
        return new self(
            issues: array_map(
                fn (ConstraintIssue $issue): ConstraintIssue => $issue->withSeverity($severity),
                $this->issues,
            ),
            score: $this->score,
        );
    }

    public function withScore(float $score): self
    {
        return new self($this->issues, $score);
    }

    public function hasHardIssues(): bool
    {
        return $this->hardIssues()->issues !== [];
    }

    public function hardIssues(): self
    {
        return $this->forSeverity(ConstraintSeverity::Hard);
    }

    public function warnings(): self
    {
        return $this->forSeverity(ConstraintSeverity::Soft);
    }

    private function forSeverity(ConstraintSeverity $severity): self
    {
        return new self(
            issues: array_values(array_filter(
                $this->issues,
                fn (ConstraintIssue $issue): bool => $issue->severity === $severity,
            )),
            score: $this->score,
        );
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(
            fn (ConstraintIssue $issue): array => $issue->toArray(),
            $this->issues,
        );
    }
}
