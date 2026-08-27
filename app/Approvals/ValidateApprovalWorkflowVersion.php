<?php

namespace App\Approvals;

use App\Enums\OrganizationPermission;
use App\Models\AcademicUnit;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ValidateApprovalWorkflowVersion
{
    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array{
     *     sequence: int,
     *     label: string,
     *     academic_unit_id: int|null,
     *     approver_selector_type: string,
     *     required_permission: string|null,
     *     role_codes: list<string>,
     *     minimum_approvals: int,
     *     allow_self_approval: bool,
     *     signatory_slot: string|null
     * }>
     */
    public function validate(Organization $organization, array $steps): array
    {
        if ($steps === []) {
            throw new InvalidArgumentException('An approval workflow must contain at least one step.');
        }

        $normalized = [];

        foreach ($steps as $index => $step) {
            $sequence = (int) ($step['sequence'] ?? 0);
            $label = trim((string) ($step['label'] ?? ''));
            $academicUnitId = $this->academicUnitId($organization, $step);
            $selectorType = (string) ($step['approver_selector_type'] ?? '');
            $requiredPermission = ($step['required_permission'] ?? null) === null
                ? null
                : (string) $step['required_permission'];
            $roleCodes = $this->roleCodes($step['role_codes'] ?? []);
            $minimumApprovals = (int) ($step['minimum_approvals'] ?? 1);
            $signatorySlot = ($step['signatory_slot'] ?? null) === null
                ? null
                : trim((string) $step['signatory_slot']);

            if ($sequence !== $index + 1) {
                throw new InvalidArgumentException('Approval workflow steps must use contiguous sequences starting at one.');
            }

            if ($label === '') {
                throw new InvalidArgumentException('Approval workflow step labels are required.');
            }

            if ($minimumApprovals < 1) {
                throw new InvalidArgumentException('Approval workflow steps require at least one approval.');
            }

            if ($selectorType === 'permission') {
                if ($requiredPermission === null || OrganizationPermission::tryFrom($requiredPermission) === null || $roleCodes !== []) {
                    throw new InvalidArgumentException('Permission selectors require one valid permission and no role selectors.');
                }
            } elseif ($selectorType === 'role') {
                if ($requiredPermission !== null || $roleCodes === []) {
                    throw new InvalidArgumentException('Role selectors require at least one role and no permission selector.');
                }

                $existingRoleCodes = DB::table('roles')
                    ->where('organization_id', $organization->id)
                    ->whereIn('code', $roleCodes)
                    ->pluck('code')
                    ->all();

                sort($existingRoleCodes);
                $expectedRoleCodes = $roleCodes;
                sort($expectedRoleCodes);

                if ($existingRoleCodes !== $expectedRoleCodes) {
                    throw new InvalidArgumentException('Approval workflow role selectors must reference organization roles.');
                }
            } else {
                throw new InvalidArgumentException('Approval workflow selectors must use permission or role.');
            }

            $normalized[] = [
                'sequence' => $sequence,
                'label' => $label,
                'academic_unit_id' => $academicUnitId,
                'approver_selector_type' => $selectorType,
                'required_permission' => $requiredPermission,
                'role_codes' => $roleCodes,
                'minimum_approvals' => $minimumApprovals,
                'allow_self_approval' => (bool) ($step['allow_self_approval'] ?? false),
                'signatory_slot' => $signatorySlot === '' ? null : $signatorySlot,
            ];
        }

        return $normalized;
    }

    /**
     * Resolve the public academic-unit input used by authoring or validate a persisted tenant key.
     *
     * @param  array<string, mixed>  $step
     */
    private function academicUnitId(Organization $organization, array $step): ?int
    {
        $publicId = $step['academic_unit_public_id'] ?? null;
        $internalId = $step['academic_unit_id'] ?? null;

        if ($publicId !== null && $internalId !== null) {
            throw new InvalidArgumentException('Approval workflow steps may use only one academic-unit identifier.');
        }

        if ($publicId !== null) {
            if (! is_string($publicId) || trim($publicId) === '') {
                throw new InvalidArgumentException('Approval workflow academic-unit identifiers must be public UUIDs.');
            }

            $academicUnit = AcademicUnit::query()
                ->where('organization_id', $organization->id)
                ->where('public_id', trim($publicId))
                ->first();

            if ($academicUnit === null) {
                throw new InvalidArgumentException('Approval workflow academic-unit selectors must reference an active organization unit.');
            }

            return $academicUnit->getKey();
        }

        if ($internalId === null) {
            return null;
        }

        if (! is_int($internalId) && ! (is_string($internalId) && ctype_digit($internalId))) {
            throw new InvalidArgumentException('Persisted approval workflow academic-unit selectors must use internal keys.');
        }

        $academicUnit = AcademicUnit::query()
            ->where('organization_id', $organization->id)
            ->whereKey((int) $internalId)
            ->first();

        if ($academicUnit === null) {
            throw new InvalidArgumentException('Approval workflow academic-unit selectors must reference an active organization unit.');
        }

        return $academicUnit->getKey();
    }

    /**
     * @return list<string>
     */
    private function roleCodes(mixed $roleCodes): array
    {
        if (! is_array($roleCodes)) {
            throw new InvalidArgumentException('Approval workflow role selectors must be an array.');
        }

        $codes = array_values(array_unique(array_filter(
            $roleCodes,
            fn (mixed $code): bool => is_string($code) && trim($code) !== '',
        )));

        if (count($codes) !== count($roleCodes)) {
            throw new InvalidArgumentException('Approval workflow role selectors must contain unique non-empty codes.');
        }

        return array_map(fn (string $code): string => trim($code), $codes);
    }
}
