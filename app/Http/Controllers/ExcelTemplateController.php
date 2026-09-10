<?php

namespace App\Http\Controllers;

use App\Actions\ActivateExcelTemplateVersion;
use App\Actions\CreateExcelTemplateVersion;
use App\Actions\InspectTemplateWorkbook;
use App\Actions\QueueTemplateExport;
use App\Actions\StoreTemplateWorkbook;
use App\Enums\ExportRunPurpose;
use App\Enums\ExportRunStatus;
use App\Enums\TemplatePlaceholder;
use App\Http\Requests\Templates\QueueTemplateExportRequest;
use App\Http\Requests\Templates\StoreExcelTemplateVersionRequest;
use App\Http\Requests\Templates\StoreTemplateWorkbookRequest;
use App\Models\ExcelTemplate;
use App\Models\ExcelTemplateVersion;
use App\Models\ExportRun;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExcelTemplateController extends Controller
{
    public function index(
        Request $request,
        Organization $currentOrganization,
        InspectTemplateWorkbook $inspectWorkbook,
    ): Response {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('viewAny', [ExcelTemplate::class, $currentOrganization]);

        $templates = ExcelTemplate::query()
            ->where('organization_id', $currentOrganization->getKey())
            ->with([
                'versions' => fn ($query) => $query
                    ->with('sourceAsset')
                    ->orderByDesc('version_number'),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (ExcelTemplate $template): array => [
                'id' => $template->public_id,
                'name' => $template->name,
                'status' => $template->status->value,
                'versions' => $template->versions->map(fn (ExcelTemplateVersion $version): array => [
                    'id' => $version->public_id,
                    'version_number' => $version->version_number,
                    'worksheet' => (string) ($version->mapping['worksheet'] ?? ''),
                    'activated_at' => $version->activated_at?->toIso8601String(),
                    'source_asset' => [
                        'id' => $version->sourceAsset->public_id,
                        'name' => $version->sourceAsset->original_name,
                        'scan_status' => $version->sourceAsset->scan_status,
                        'size' => $version->sourceAsset->size,
                    ],
                ])->values()->all(),
            ])->values()->all();

        $runs = ExportRun::query()
            ->where('organization_id', $currentOrganization->getKey())
            ->with(['artifact', 'templateVersion.template', 'timetableVersion.timetable.academicPeriod'])
            ->orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(fn (ExportRun $run): array => [
                'id' => $run->public_id,
                'purpose' => $run->purpose->value,
                'status' => $run->status->value,
                'created_at' => $run->created_at?->toIso8601String(),
                'completed_at' => $run->completed_at?->toIso8601String(),
                'error' => $run->error,
                'template_name' => $run->templateVersion->template->name,
                'template_version_number' => $run->templateVersion->version_number,
                'timetable_name' => $run->timetableVersion->timetable->name,
                'timetable_version_number' => $run->timetableVersion->version_number,
                'download_url' => $run->status === ExportRunStatus::Completed && $run->artifact !== null
                    ? URL::temporarySignedRoute('templates.exports.download', now()->addMinutes(5), [
                        'current_organization' => $currentOrganization->slug,
                        'export_run' => $run->public_id,
                    ])
                    : null,
            ])->values()->all();

        return Inertia::render('templates/Index', [
            'templates' => $templates,
            'timetableVersions' => TimetableVersion::query()
                ->where('organization_id', $currentOrganization->getKey())
                ->with('timetable.academicPeriod')
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(fn (TimetableVersion $version): array => [
                    'id' => $version->public_id,
                    'name' => $version->timetable->name,
                    'period_name' => $version->timetable->academicPeriod->name,
                    'number' => $version->version_number,
                    'status' => $version->status->value,
                ])->values()->all(),
            'runs' => $runs,
            'uploadedWorkbook' => $this->uploadedWorkbook($request, $currentOrganization, $actor, $inspectWorkbook),
            'placeholderCatalog' => array_map(fn (TemplatePlaceholder $placeholder): array => [
                'key' => $placeholder->value,
                'label' => $placeholder->label(),
            ], TemplatePlaceholder::cases()),
            'canCreateTemplateVersions' => $actor->can('create', [ExcelTemplateVersion::class, $currentOrganization]),
            'schedulingGranularity' => $currentOrganization->scheduling_granularity,
        ]);
    }

    public function storeWorkbook(
        StoreTemplateWorkbookRequest $request,
        Organization $currentOrganization,
        StoreTemplateWorkbook $storeWorkbook,
    ): RedirectResponse {
        $workbook = $request->file('workbook');

        if ($workbook === null) {
            throw ValidationException::withMessages(['workbook' => 'Choose an .xlsx workbook to upload.']);
        }

        $asset = $storeWorkbook->handle($currentOrganization, $this->actor($request), $workbook);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Workbook uploaded. Complete security scanning before mapping it.'),
        ]);

        return to_route('templates.index', [
            'current_organization' => $currentOrganization->slug,
            'workbook' => $asset->public_id,
        ]);
    }

    public function storeVersion(
        StoreExcelTemplateVersionRequest $request,
        Organization $currentOrganization,
        CreateExcelTemplateVersion $createVersion,
    ): RedirectResponse {
        $attributes = $request->validated();
        $mapping = $attributes['mapping'] ?? null;

        if (! is_array($mapping)) {
            throw ValidationException::withMessages(['mapping' => 'A workbook mapping is required.']);
        }

        $asset = FileAsset::query()
            ->where('organization_id', $currentOrganization->getKey())
            ->where('public_id', $attributes['file_asset_id'])
            ->firstOrFail();
        $template = isset($attributes['template_id']) && is_string($attributes['template_id'])
            ? ExcelTemplate::query()
                ->where('organization_id', $currentOrganization->getKey())
                ->where('public_id', $attributes['template_id'])
                ->firstOrFail()
            : null;

        $createVersion->handle(
            organization: $currentOrganization,
            actor: $this->actor($request),
            sourceAsset: $asset,
            name: is_string($attributes['name'] ?? null) ? $attributes['name'] : '',
            mapping: $mapping,
            template: $template,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template version saved as a draft.')]);

        return to_route('templates.index', ['current_organization' => $currentOrganization->slug]);
    }

    public function activateVersion(
        Request $request,
        Organization $currentOrganization,
        ExcelTemplateVersion $excelTemplateVersion,
        ActivateExcelTemplateVersion $activateVersion,
    ): RedirectResponse {
        abort_unless($excelTemplateVersion->organization_id === $currentOrganization->getKey(), 404);

        $activateVersion->handle($currentOrganization, $this->actor($request), $excelTemplateVersion);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template version activated.')]);

        return to_route('templates.index', ['current_organization' => $currentOrganization->slug]);
    }

    public function storeExport(
        QueueTemplateExportRequest $request,
        Organization $currentOrganization,
        QueueTemplateExport $queueExport,
    ): RedirectResponse {
        $attributes = $request->validated();
        $timetableVersion = TimetableVersion::query()
            ->where('organization_id', $currentOrganization->getKey())
            ->where('public_id', $attributes['timetable_version_id'])
            ->firstOrFail();
        $templateVersion = ExcelTemplateVersion::query()
            ->where('organization_id', $currentOrganization->getKey())
            ->where('public_id', $attributes['template_version_id'])
            ->firstOrFail();
        $purpose = ExportRunPurpose::from((string) $attributes['purpose']);

        $queueExport->handle(
            organization: $currentOrganization,
            actor: $this->actor($request),
            timetableVersion: $timetableVersion,
            templateVersion: $templateVersion,
            purpose: $purpose,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $purpose === ExportRunPurpose::Preview
                ? __('Preview queued.')
                : __('Export queued.'),
        ]);

        return to_route('templates.index', ['current_organization' => $currentOrganization->slug]);
    }

    public function download(
        Request $request,
        Organization $currentOrganization,
        ExportRun $exportRun,
    ): StreamedResponse {
        abort_unless($exportRun->organization_id === $currentOrganization->getKey(), 404);
        Gate::forUser($this->actor($request))->authorize('view', $exportRun);
        abort_unless($exportRun->status === ExportRunStatus::Completed && $exportRun->artifact !== null, 404);

        $artifact = $exportRun->artifact;
        abort_unless(Storage::disk($artifact->disk)->exists($artifact->path), 404);

        $templateName = $exportRun->templateVersion->template->name;
        $filename = Str::slug($templateName).'-v'.$exportRun->templateVersion->version_number.'.xlsx';

        return Storage::disk($artifact->disk)->download($artifact->path, $filename);
    }

    /**
     * @return array{
     *     id: string,
     *     inspection: array<mixed>|null,
     *     inspection_error: string|null,
     *     name: string,
     *     scan_status: string,
     *     size: int
     * }|null
     */
    private function uploadedWorkbook(
        Request $request,
        Organization $organization,
        User $actor,
        InspectTemplateWorkbook $inspectWorkbook,
    ): ?array {
        $publicId = $request->query('workbook');

        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $asset = FileAsset::query()
            ->where('organization_id', $organization->getKey())
            ->where('public_id', $publicId)
            ->firstOrFail();
        Gate::forUser($actor)->authorize('view', $asset);
        $inspection = null;
        $inspectionError = null;

        if ($asset->scan_status === FileAsset::ScanClean) {
            try {
                $inspection = $inspectWorkbook->handle($organization, $actor, $asset)->worksheets;
            } catch (ValidationException $exception) {
                $inspectionError = $exception->errors()['workbook'][0] ?? 'The workbook could not be inspected.';
            }
        }

        return [
            'id' => $asset->public_id,
            'inspection' => $inspection,
            'inspection_error' => $inspectionError,
            'name' => $asset->original_name,
            'scan_status' => $asset->scan_status,
            'size' => $asset->size,
        ];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
