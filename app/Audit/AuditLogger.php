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
        DB::table('audit_events')->insert([
            'organization_id' => $organization?->id,
            'actor_user_id' => $actor?->id,
            'correlation_id' => Context::get('correlation_id', fn (): string => (string) Str::uuid()),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $this->subjectIdentifier($subject),
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'occurred_at' => now(),
        ]);
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
}
