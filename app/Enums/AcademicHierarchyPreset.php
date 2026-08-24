<?php

namespace App\Enums;

enum AcademicHierarchyPreset: string
{
    case Preschool = 'preschool';
    case KindergartenToGrade12 = 'k12';
    case SeniorHigh = 'senior_high';
    case University = 'university';
    case TrainingCenter = 'training_center';

    public function label(): string
    {
        return match ($this) {
            self::Preschool => 'Preschool',
            self::KindergartenToGrade12 => 'K-12',
            self::SeniorHigh => 'Senior high school',
            self::University => 'University',
            self::TrainingCenter => 'Training center',
        };
    }
}
