<?php

namespace App\Audit;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        ?Organization $organization = null,
        ?User $actor = null,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
    ): void {
        $attributes = [
            'organization_id' => $organization?->id,
            'actor_user_id' => $actor?->id,
            'impersonator_user_id' => $this->impersonatorUserId(),
            'correlation_id' => Context::get('correlation_id', fn (): string => (string) Str::uuid()),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $this->subjectIdentifier($subject),
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'occurred_at' => now(),
        ];

        if ($organization === null && DB::getDriverName() === 'pgsql') {
            DB::transaction(function () use ($attributes): void {
                DB::statement("select set_config('app.allow_global_audit_event_insert', 'true', true)");
                DB::table('audit_events')->insert($attributes);
            });

            return;
        }

        DB::table('audit_events')->insert($attributes);
    }

    private function subjectIdentifier(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        $publicId = $subject->getAttribute('public_id');

        return is_string($publicId) && $publicId !== ''
            ? $publicId
            : (string) $subject->getRouteKey();
    }

    private function impersonatorUserId(): ?int
    {
        $impersonatorUserId = Context::get('impersonator_user_id');

        return is_int($impersonatorUserId) ? $impersonatorUserId : null;
    }
}
