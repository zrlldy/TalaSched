<?php

namespace App\Academic;

use App\Audit\AuditLogger;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\Organization;
use App\Models\StudentGroup;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcademicHierarchyService
{
    public function __construct(
        private TenantContext $tenantContext,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Create a unit and its closure rows atomically.
     */
    public function create(
        Organization $organization,
        AcademicUnitType $type,
        string $name,
        ?string $code = null,
        ?AcademicUnit $parent = null,
        ?CarbonInterface $activeFrom = null,
        ?CarbonInterface $activeUntil = null,
        ?User $actor = null,
    ): AcademicUnit {
        return $this->tenantContext->run($organization, function () use ($organization, $type, $name, $code, $parent, $activeFrom, $activeUntil, $actor): AcademicUnit {
            return DB::transaction(function () use ($organization, $type, $name, $code, $parent, $activeFrom, $activeUntil, $actor): AcademicUnit {
                $lockedType = AcademicUnitType::query()
                    ->whereKey($type->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedParent = $parent
                    ? AcademicUnit::query()
                        ->whereKey($parent->getKey())
                        ->where('organization_id', $organization->getKey())
                        ->lockForUpdate()
                        ->firstOrFail()
                    : null;

                $this->assertAllowedParentType($organization, $lockedType, $lockedParent);

                $unit = AcademicUnit::query()->create([
                    'organization_id' => $organization->getKey(),
                    'academic_unit_type_id' => $lockedType->getKey(),
                    'parent_id' => $lockedParent?->getKey(),
                    'code' => $code,
                    'name' => $name,
                    'active_from' => $activeFrom,
                    'active_until' => $activeUntil,
                ]);

                $this->insertClosureRows($organization, $unit, $lockedParent);
                $unit->refresh();

                $this->auditLogger->record(
                    action: 'academic_unit.created',
                    organization: $organization,
                    actor: $actor,
                    subject: $unit,
                    after: $this->unitSnapshot($unit),
                );

                return $unit;
            }, attempts: 3);
        });
    }

    /**
     * Move a unit and rewrite its subtree closure paths atomically.
     */
    public function move(Organization $organization, AcademicUnit $unit, ?AcademicUnit $newParent, ?User $actor = null): AcademicUnit
    {
        return $this->tenantContext->run($organization, function () use ($organization, $unit, $newParent, $actor): AcademicUnit {
            return DB::transaction(function () use ($organization, $unit, $newParent, $actor): AcademicUnit {
                $unitIdsToLock = collect([$unit->getKey(), $newParent?->getKey()])
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values();
                $lockedUnits = AcademicUnit::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereIn('id', $unitIdsToLock)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $lockedUnit = $lockedUnits->get($unit->getKey());
                $lockedParent = $newParent ? $lockedUnits->get($newParent->getKey()) : null;

                if (! $lockedUnit instanceof AcademicUnit) {
                    throw (new ModelNotFoundException)->setModel(AcademicUnit::class, [$unit->getKey()]);
                }

                if ($newParent && ! $lockedParent instanceof AcademicUnit) {
                    throw (new ModelNotFoundException)->setModel(AcademicUnit::class, [$newParent->getKey()]);
                }

                if ($lockedUnit->parent_id === $lockedParent?->getKey()) {
                    return $lockedUnit->fresh();
                }

                $subtreeRows = DB::table('academic_unit_closure')
                    ->where('organization_id', $organization->getKey())
                    ->where('ancestor_id', $lockedUnit->getKey())
                    ->orderBy('depth')
                    ->lockForUpdate()
                    ->get();
                $subtreeIds = $subtreeRows->pluck('descendant_id')->unique()->values();

                if ($subtreeIds->isEmpty()) {
                    $subtreeIds = collect([$lockedUnit->getKey()]);
                    DB::table('academic_unit_closure')->insert([
                        'organization_id' => $organization->getKey(),
                        'ancestor_id' => $lockedUnit->getKey(),
                        'descendant_id' => $lockedUnit->getKey(),
                        'depth' => 0,
                    ]);
                }

                if ($lockedParent && $subtreeIds->contains($lockedParent->getKey())) {
                    throw ValidationException::withMessages([
                        'parent_id' => __('An academic unit cannot be moved below one of its descendants.'),
                    ]);
                }

                $lockedType = AcademicUnitType::query()
                    ->whereKey($lockedUnit->academic_unit_type_id)
                    ->where('organization_id', $organization->getKey())
                    ->firstOrFail();

                $this->assertAllowedParentType($organization, $lockedType, $lockedParent);

                $relativeRows = DB::table('academic_unit_closure')
                    ->where('organization_id', $organization->getKey())
                    ->where('ancestor_id', $lockedUnit->getKey())
                    ->whereIn('descendant_id', $subtreeIds)
                    ->orderBy('depth')
                    ->get();

                DB::table('academic_unit_closure')
                    ->where('organization_id', $organization->getKey())
                    ->whereIn('descendant_id', $subtreeIds)
                    ->whereNotIn('ancestor_id', $subtreeIds)
                    ->delete();

                if ($lockedParent) {
                    $parentAncestors = DB::table('academic_unit_closure')
                        ->where('organization_id', $organization->getKey())
                        ->where('descendant_id', $lockedParent->getKey())
                        ->orderBy('depth')
                        ->lockForUpdate()
                        ->get();
                    $newClosureRows = [];

                    foreach ($parentAncestors as $parentAncestor) {
                        foreach ($relativeRows as $relativeRow) {
                            $newClosureRows[] = [
                                'organization_id' => $organization->getKey(),
                                'ancestor_id' => $parentAncestor->ancestor_id,
                                'descendant_id' => $relativeRow->descendant_id,
                                'depth' => $parentAncestor->depth + 1 + $relativeRow->depth,
                            ];
                        }
                    }

                    if ($newClosureRows !== []) {
                        DB::table('academic_unit_closure')->insert($newClosureRows);
                    }
                }

                $before = ['parent_id' => $this->parentPublicId($organization, $lockedUnit->parent_id)];
                $lockedUnit->update(['parent_id' => $lockedParent?->getKey()]);
                $lockedUnit->refresh();

                $this->auditLogger->record(
                    action: 'academic_unit.moved',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedUnit,
                    before: $before,
                    after: ['parent_id' => $lockedParent?->public_id],
                );

                return $lockedUnit;
            }, attempts: 3);
        });
    }

    /**
     * Archive a leaf unit with no active student groups.
     */
    public function archive(Organization $organization, AcademicUnit $unit, ?User $actor = null): AcademicUnit
    {
        return $this->tenantContext->run($organization, function () use ($organization, $unit, $actor): AcademicUnit {
            return DB::transaction(function () use ($organization, $unit, $actor): AcademicUnit {
                $lockedUnit = AcademicUnit::query()
                    ->whereKey($unit->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedUnit->children()->exists()) {
                    throw ValidationException::withMessages([
                        'unit' => __('Archive child units before archiving their parent.'),
                    ]);
                }

                if (StudentGroup::query()->where('academic_unit_id', $lockedUnit->getKey())->exists()) {
                    throw ValidationException::withMessages([
                        'unit' => __('Reassign active student groups before archiving this unit.'),
                    ]);
                }

                $before = $this->unitSnapshot($lockedUnit);
                $lockedUnit->delete();
                $lockedUnit->load(['parent', 'type']);

                $this->auditLogger->record(
                    action: 'academic_unit.archived',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedUnit,
                    before: $before,
                    after: ['archived_at' => $lockedUnit->deleted_at?->toIso8601String()],
                );

                return $lockedUnit;
            }, attempts: 3);
        });
    }

    /**
     * Query all units or one root's closure descendants within the organization.
     *
     * @return Collection<int, AcademicUnit>
     */
    public function query(Organization $organization, ?string $rootPublicId = null, bool $includeArchived = false): Collection
    {
        return $this->tenantContext->run($organization, function () use ($organization, $rootPublicId, $includeArchived): Collection {
            if ($rootPublicId === null) {
                return $this->academicUnitQuery($includeArchived)
                    ->where('organization_id', $organization->getKey())
                    ->orderBy('name')
                    ->get();
            }

            $root = $this->academicUnitQuery($includeArchived)
                ->where('organization_id', $organization->getKey())
                ->where('public_id', $rootPublicId)
                ->firstOrFail();

            $descendants = $root->descendants()
                ->wherePivot('organization_id', $organization->getKey())
                ->orderByPivot('depth')
                ->orderBy('academic_units.name');

            if ($includeArchived) {
                $descendants->getQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
            }

            return $descendants->get();
        });
    }

    /**
     * Build an academic-unit query with optional archived records.
     *
     * @return Builder<AcademicUnit>
     */
    private function academicUnitQuery(bool $includeArchived): Builder
    {
        $query = AcademicUnit::query();

        if ($includeArchived) {
            $query->withTrashed();
        }

        return $query;
    }

    private function assertAllowedParentType(Organization $organization, AcademicUnitType $childType, ?AcademicUnit $parent): void
    {
        if (! $parent) {
            return;
        }

        $allowed = DB::table('academic_unit_type_edges')
            ->where('organization_id', $organization->getKey())
            ->where('parent_type_id', $parent->academic_unit_type_id)
            ->where('child_type_id', $childType->getKey())
            ->exists();

        if (! $allowed) {
            throw ValidationException::withMessages([
                'parent_id' => __('This academic unit type cannot be nested under the selected parent type.'),
            ]);
        }
    }

    private function insertClosureRows(Organization $organization, AcademicUnit $unit, ?AcademicUnit $parent): void
    {
        $rows = [[
            'organization_id' => $organization->getKey(),
            'ancestor_id' => $unit->getKey(),
            'descendant_id' => $unit->getKey(),
            'depth' => 0,
        ]];

        if ($parent) {
            $ancestors = DB::table('academic_unit_closure')
                ->where('organization_id', $organization->getKey())
                ->where('descendant_id', $parent->getKey())
                ->orderBy('depth')
                ->lockForUpdate()
                ->get();

            foreach ($ancestors as $ancestor) {
                $rows[] = [
                    'organization_id' => $organization->getKey(),
                    'ancestor_id' => $ancestor->ancestor_id,
                    'descendant_id' => $unit->getKey(),
                    'depth' => $ancestor->depth + 1,
                ];
            }
        }

        DB::table('academic_unit_closure')->insert($rows);
    }

    /**
     * @return array{id: string, name: string, code: string|null, active_from: string|null, active_until: string|null}
     */
    private function unitSnapshot(AcademicUnit $academicUnit): array
    {
        return [
            'id' => $academicUnit->public_id,
            'name' => $academicUnit->name,
            'code' => $academicUnit->code,
            'active_from' => $academicUnit->active_from?->toDateString(),
            'active_until' => $academicUnit->active_until?->toDateString(),
        ];
    }

    private function parentPublicId(Organization $organization, ?int $parentId): ?string
    {
        if ($parentId === null) {
            return null;
        }

        return AcademicUnit::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($parentId)
            ->value('public_id');
    }
}
