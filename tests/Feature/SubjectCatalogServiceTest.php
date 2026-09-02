<?php

use App\Catalog\SubjectCatalogService;
use App\Enums\DeliveryMode;
use App\Enums\SubjectComponentKind;
use App\Models\Feature;
use App\Models\Organization;
use App\Models\RoomType;
use App\Models\Subject;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('catalog service manages subjects, components, and room requirements', function (): void {
    $organization = Organization::factory()->create();
    $service = app(SubjectCatalogService::class);
    $roomType = RoomType::factory()->forOrganization($organization)->create();
    $feature = Feature::factory()->forOrganization($organization)->create();

    $subject = $service->createSubject(
        $organization,
        'CS101',
        'Introduction to Programming',
        3.0,
        'Foundational programming course',
    );
    $component = $service->addComponent(
        $organization,
        $subject,
        SubjectComponentKind::Lecture,
        'Lecture',
        180,
        2,
        90,
        30,
        DeliveryMode::Physical,
    );
    $component = $service->setRequirements($organization, $component, [$roomType], [[
        'feature' => $feature,
        'minimum_quantity' => 1,
    ]]);

    expect($subject->units)->toBe('3.00')
        ->and($component->kind)->toBe(SubjectComponentKind::Lecture)
        ->and($component->weekly_minutes)->toBe(180)
        ->and($component->sessions_per_week)->toBe(2)
        ->and($component->default_duration_minutes)->toBe(90)
        ->and($component->delivery_mode)->toBe(DeliveryMode::Physical)
        ->and($component->roomTypes->first()->is($roomType))->toBeTrue()
        ->and($component->features->first()->is($feature))->toBeTrue()
        ->and(DB::table('subject_component_features')
            ->where('subject_component_id', $component->getKey())
            ->value('minimum_quantity'))->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'subject.created')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'subject.component_created')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'subject.component_requirements_saved')->count())->toBe(1);

    $archivedSubject = $service->archiveSubject($organization, $subject);

    expect($archivedSubject->trashed())->toBeTrue()
        ->and($archivedSubject->is_active)->toBeFalse()
        ->and(Subject::query()->whereKey($subject->getKey())->exists())->toBeFalse()
        ->and(DB::table('subject_components')->where('id', $component->getKey())->exists())->toBeTrue()
        ->and(DB::table('audit_events')->where('action', 'subject.archived')->count())->toBe(1);

    $restoredSubject = $service->restoreSubject($organization, $archivedSubject);

    expect($restoredSubject->trashed())->toBeFalse()
        ->and($restoredSubject->is_active)->toBeTrue()
        ->and(Subject::query()->whereKey($subject->getKey())->exists())->toBeTrue()
        ->and(DB::table('audit_events')->where('action', 'subject.restored')->count())->toBe(1);
});

test('catalog service rejects invalid values and foreign room requirements', function (): void {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $service = app(SubjectCatalogService::class);
    $subject = $service->createSubject($organization, 'MATH101', 'Mathematics');
    $foreignRoomType = RoomType::factory()->forOrganization($foreignOrganization)->create();
    $foreignFeature = Feature::factory()->forOrganization($foreignOrganization)->create();

    expect(fn () => $service->createSubject($organization, '', 'Missing code'))
        ->toThrow(ValidationException::class, 'required')
        ->and(fn () => $service->createSubject($organization, 'BAD', 'Bad units', -1.0))
        ->toThrow(ValidationException::class, 'units')
        ->and(fn () => $service->addComponent(
            $organization,
            $subject,
            SubjectComponentKind::Laboratory,
            'Lab',
            0,
            1,
            90,
        ))->toThrow(ValidationException::class, 'positive')
        ->and(fn () => $service->setRequirements($organization, $subject->components()->create([
            'organization_id' => $organization->getKey(),
            'kind' => SubjectComponentKind::Lecture,
            'name' => 'Lecture',
            'weekly_minutes' => 90,
            'sessions_per_week' => 1,
            'default_duration_minutes' => 90,
            'delivery_mode' => DeliveryMode::Physical,
        ]), [$foreignRoomType], []))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $service->setRequirements($organization, $subject->components()->firstOrFail(), [], [[
            'feature' => $foreignFeature,
            'minimum_quantity' => 1,
        ]]))->toThrow(ModelNotFoundException::class);
});
