<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\GenerationStatus;
use Database\Factories\GenerationRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $public_id
 * @property int $organization_id
 * @property int $timetable_id
 * @property int $source_timetable_version_id
 * @property int|null $output_timetable_version_id
 * @property int|null $requested_by
 * @property int $input_schema_version
 * @property array<string, mixed> $input_snapshot
 * @property int|null $seed
 * @property GenerationStatus $status
 * @property int|null $progress_percent
 * @property string|null $progress_message
 * @property array<string, mixed>|null $diagnostics
 * @property Carbon|null $cancel_requested_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property-read Timetable $timetable
 * @property-read TimetableVersion $sourceVersion
 * @property-read TimetableVersion|null $outputVersion
 * @property-read User|null $requestedBy
 */
#[Fillable(['organization_id', 'timetable_id', 'source_timetable_version_id', 'output_timetable_version_id', 'requested_by', 'input_schema_version', 'input_snapshot', 'seed', 'status', 'progress_percent', 'progress_message', 'diagnostics', 'cancel_requested_at', 'started_at', 'completed_at', 'failed_at'])]
class GenerationRun extends Model
{
    /** @use HasFactory<GenerationRunFactory> */
    use HasFactory, HasPublicId;

    /** @return BelongsTo<Timetable, $this> */
    public function timetable(): BelongsTo
    {
        return $this->belongsTo(Timetable::class);
    }

    /** @return BelongsTo<TimetableVersion, $this> */
    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(TimetableVersion::class, 'source_timetable_version_id');
    }

    /** @return BelongsTo<TimetableVersion, $this> */
    public function outputVersion(): BelongsTo
    {
        return $this->belongsTo(TimetableVersion::class, 'output_timetable_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    protected function casts(): array
    {
        return [
            'input_snapshot' => 'array',
            'seed' => 'integer',
            'status' => GenerationStatus::class,
            'progress_percent' => 'integer',
            'diagnostics' => 'array',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
