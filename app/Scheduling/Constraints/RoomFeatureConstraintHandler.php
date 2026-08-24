<?php

namespace App\Scheduling\Constraints;

use App\Enums\ConstraintSeverity;
use App\Models\ConstraintConfiguration;
use App\Models\Feature;
use App\Scheduling\ConstraintResult;
use App\Scheduling\SchedulingContext;

class RoomFeatureConstraintHandler extends AbstractConstraintHandler
{
    public function code(): string
    {
        return 'room_features_required';
    }

    public function defaultSeverity(): ConstraintSeverity
    {
        return ConstraintSeverity::Hard;
    }

    public function evaluate(SchedulingContext $context, ?ConstraintConfiguration $configuration): ConstraintResult
    {
        $issues = [];

        foreach ($context->rooms as $room) {
            foreach ($context->offeringComponent->features as $requiredFeature) {
                $roomFeature = $room->features->first(
                    fn (Feature $feature): bool => $feature->getKey() === $requiredFeature->getKey(),
                );
                $requiredQuantity = $requiredFeature->pivot->minimum_quantity ?? 1;
                $availableQuantity = $roomFeature === null
                    ? 0
                    : ($roomFeature->pivot->quantity ?? 1);

                if ((int) $availableQuantity >= (int) $requiredQuantity) {
                    continue;
                }

                $issues[] = $this->issue(
                    $this->code(),
                    ConstraintSeverity::Hard,
                    'resources',
                    'The room does not provide the required feature quantity.',
                    $context->resource($room->scheduling_resource_id),
                    details: [
                        'feature_id' => $requiredFeature->getKey(),
                        'feature_code' => $requiredFeature->code,
                        'required_quantity' => (int) $requiredQuantity,
                        'available_quantity' => (int) $availableQuantity,
                    ],
                );
            }
        }

        return new ConstraintResult($issues);
    }
}
