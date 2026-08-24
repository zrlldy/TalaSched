<?php

namespace App\Scheduling\Constraints;

use App\Enums\ConstraintSeverity;
use App\Models\ConstraintConfiguration;
use App\Scheduling\ConstraintResult;
use App\Scheduling\SchedulingContext;

class RoomTypeConstraintHandler extends AbstractConstraintHandler
{
    public function code(): string
    {
        return 'room_type_required';
    }

    public function defaultSeverity(): ConstraintSeverity
    {
        return ConstraintSeverity::Hard;
    }

    public function evaluate(SchedulingContext $context, ?ConstraintConfiguration $configuration): ConstraintResult
    {
        $requiredRoomTypeId = $context->offeringComponent->required_room_type_id;

        if ($requiredRoomTypeId === null) {
            return ConstraintResult::empty();
        }

        $issues = [];

        foreach ($context->rooms as $room) {
            if ($room->room_type_id === $requiredRoomTypeId) {
                continue;
            }

            $issues[] = $this->issue(
                $this->code(),
                ConstraintSeverity::Hard,
                'resources',
                'The room does not satisfy the required room type.',
                $context->resource($room->scheduling_resource_id),
            );
        }

        return new ConstraintResult($issues);
    }
}
