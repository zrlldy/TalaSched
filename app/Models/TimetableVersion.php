<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\TimetableVersionStatus;
use Database\Factories\TimetableVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property TimetableVersionStatus $status
 * @property int $organization_id
 * @property int $timetable_id
 * @property int $version_number
 * @property int $entries_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $published_at
 * @property-read Timetable $timetable
 */
#[Fillable(['organization_id', 'timetable_id', 'based_on_version_id', 'version_number', 'status', 'lock_version', 'created_by', 'published_by', 'submitted_at', 'approved_at', 'published_at'])]
class TimetableVersion extends Model
{
    /** @use HasFactory<TimetableVersionFactory> */
    use HasFactory, HasPublicId;

    /** @return BelongsTo<Timetable, $this> */
    public function timetable(): BelongsTo
    {
        return $this->belongsTo(Timetable::class);
    }

    /** @return HasMany<ScheduleEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(ScheduleEntry::class);
    }

    protected function casts(): array
    {
        return [
            'status' => TimetableVersionStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
