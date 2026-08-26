<?php

namespace App\Http\Controllers\Api\Admin;

use App\Application\Support\TransactionManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreExamTemplateRequest;
use App\Http\Requests\Admin\StoreExamTemplateVersionRequest;
use App\Http\Requests\Admin\UpdateExamTemplateRequest;
use App\Http\Requests\Admin\UpdateExamTemplateVersionRequest;
use App\Http\Responses\ApiResponse;
use App\Models\CurriculumVersion;
use App\Models\ExamTemplate;
use App\Models\ExamTemplateVersion;
use Illuminate\Http\JsonResponse;

class AdminExamTemplateController extends Controller
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function index(
        string $curriculumVersionId,
    ): JsonResponse {
        $version = CurriculumVersion::query()
            ->whereKey($curriculumVersionId)
            ->firstOrFail();

        $templates = ExamTemplate::query()
            ->where(
                'curriculum_version_id',
                $version->id,
            )
            ->withCount('versions')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(
                fn (ExamTemplate $template): array =>
                    $this->templateData(
                        $template
                    )
            )
            ->values()
            ->all();

        return ApiResponse::success($templates);
    }

    public function store(
        StoreExamTemplateRequest $request,
        string $curriculumVersionId,
    ): JsonResponse {
        $version = CurriculumVersion::query()
            ->whereKey($curriculumVersionId)
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Exam templates may only be authored in draft curriculum versions.',
                409,
            );
        }

        $template = $this->transactions->run(
            fn (): ExamTemplate =>
                ExamTemplate::query()->create([
                    'curriculum_version_id' =>
                        $version->id,
                    'name' =>
                        $request->validated(
                            'name'
                        ),
                    'description' =>
                        $request->validated(
                            'description'
                        ),
                    'status' => 'active',
                    'published_version_id' => null,
                ])
        );

        $template->loadCount('versions');

        return ApiResponse::success(
            $this->templateData($template),
            201,
        );
    }

    public function update(
        UpdateExamTemplateRequest $request,
        string $examTemplateId,
    ): JsonResponse {
        $template = ExamTemplate::query()
            ->whereKey($examTemplateId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey(
                $template->curriculum_version_id
            )
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Exam templates may only be edited in draft curriculum versions.',
                409,
            );
        }

        if ($template->status !== 'active') {
            return ApiResponse::error(
                'exam_template_not_active',
                'Only active exam templates may be edited.',
                409,
            );
        }

        $this->transactions->run(
            fn (): bool => $template->update(
                $request->validated()
            )
        );

        $template->refresh()->loadCount('versions');

        return ApiResponse::success(
            $this->templateData($template)
        );
    }

    public function versions(
        string $examTemplateId,
    ): JsonResponse {
        $template = ExamTemplate::query()
            ->whereKey($examTemplateId)
            ->firstOrFail();

        $versions = ExamTemplateVersion::query()
            ->where(
                'exam_template_id',
                $template->id,
            )
            ->orderBy('version_number')
            ->orderBy('id')
            ->get()
            ->map(
                fn (
                    ExamTemplateVersion $version
                ): array => $this->versionData(
                    $version
                )
            )
            ->values()
            ->all();

        return ApiResponse::success($versions);
    }

    public function storeVersion(
        StoreExamTemplateVersionRequest $request,
        string $examTemplateId,
    ): JsonResponse {
        $template = ExamTemplate::query()
            ->whereKey($examTemplateId)
            ->firstOrFail();

        $curriculumVersion =
            CurriculumVersion::query()
                ->whereKey(
                    $template
                        ->curriculum_version_id
                )
                ->firstOrFail();

        if ($curriculumVersion->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Exam template versions may only be authored in draft curriculum versions.',
                409,
            );
        }

        if ($template->status !== 'active') {
            return ApiResponse::error(
                'exam_template_not_active',
                'New versions may only be added to active exam templates.',
                409,
            );
        }

        $version = $this->transactions->run(
            fn (): ExamTemplateVersion =>
                ExamTemplateVersion::query()
                    ->create([
                        'exam_template_id' =>
                            $template->id,
                        'curriculum_version_id' =>
                            $template
                                ->curriculum_version_id,
                        'version_number' =>
                            $request->validated(
                                'version_number'
                            ),
                        'label' =>
                            $request->validated(
                                'label'
                            ),
                        'status' => 'draft',
                        'rules_payload' =>
                            $request->validated(
                                'rules_payload'
                            ),
                        'rules_schema_version' =>
                            $request->validated(
                                'rules_schema_version'
                            ),
                    ])
        );

        return ApiResponse::success(
            $this->versionData($version),
            201,
        );
    }

    public function updateVersion(
        UpdateExamTemplateVersionRequest $request,
        string $examTemplateVersionId,
    ): JsonResponse {
        $version = ExamTemplateVersion::query()
            ->whereKey($examTemplateVersionId)
            ->firstOrFail();

        $template = ExamTemplate::query()
            ->whereKey(
                $version->exam_template_id
            )
            ->firstOrFail();

        $curriculumVersion =
            CurriculumVersion::query()
                ->whereKey(
                    $version
                        ->curriculum_version_id
                )
                ->firstOrFail();

        if ($curriculumVersion->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Exam template versions may only be edited in draft curriculum versions.',
                409,
            );
        }

        if ($template->status !== 'active') {
            return ApiResponse::error(
                'exam_template_not_active',
                'Only versions of active templates may be edited.',
                409,
            );
        }

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'exam_template_version_not_draft',
                'Only draft exam template versions may be edited.',
                409,
            );
        }

        $this->transactions->run(
            fn (): bool => $version->update(
                $request->validated()
            )
        );

        return ApiResponse::success(
            $this->versionData(
                $version->refresh()
            )
        );
    }

    public function archive(
        string $examTemplateId,
    ): JsonResponse {
        $template = ExamTemplate::query()
            ->whereKey($examTemplateId)
            ->firstOrFail();

        $curriculumVersion =
            CurriculumVersion::query()
                ->whereKey(
                    $template
                        ->curriculum_version_id
                )
                ->firstOrFail();

        if ($curriculumVersion->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Exam templates may only be archived in draft curriculum versions.',
                409,
            );
        }

        if ($template->status === 'archived') {
            $template->loadCount('versions');

            return ApiResponse::success(
                $this->templateData($template)
            );
        }

        $this->transactions->run(
            fn (): int =>
                ExamTemplate::query()
                    ->whereKey($template->id)
                    ->update([
                        'status' => 'archived',
                        'updated_at' => now(),
                    ])
        );

        $template->refresh()->loadCount('versions');

        return ApiResponse::success(
            $this->templateData($template)
        );
    }

    public function activate(
        string $examTemplateId,
    ): JsonResponse {
        $template = ExamTemplate::query()
            ->whereKey($examTemplateId)
            ->firstOrFail();

        $curriculumVersion =
            CurriculumVersion::query()
                ->whereKey(
                    $template
                        ->curriculum_version_id
                )
                ->firstOrFail();

        if ($curriculumVersion->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Exam templates may only be activated in draft curriculum versions.',
                409,
            );
        }

        if ($template->status === 'active') {
            $template->loadCount('versions');

            return ApiResponse::success(
                $this->templateData($template)
            );
        }

        $this->transactions->run(
            fn (): int =>
                ExamTemplate::query()
                    ->whereKey($template->id)
                    ->update([
                        'status' => 'active',
                        'updated_at' => now(),
                    ])
        );

        $template->refresh()->loadCount('versions');

        return ApiResponse::success(
            $this->templateData($template)
        );
    }

    public function publishVersion(
        string $examTemplateVersionId,
    ): JsonResponse {
        $version = ExamTemplateVersion::query()
            ->whereKey($examTemplateVersionId)
            ->firstOrFail();

        $template = ExamTemplate::query()
            ->whereKey(
                $version->exam_template_id
            )
            ->firstOrFail();

        $curriculumVersion =
            CurriculumVersion::query()
                ->whereKey(
                    $version
                        ->curriculum_version_id
                )
                ->firstOrFail();

        if ($curriculumVersion->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Exam template versions may only be published while the curriculum version is draft.',
                409,
            );
        }

        if ($template->status !== 'active') {
            return ApiResponse::error(
                'exam_template_not_active',
                'Only versions of active exam templates may be published.',
                409,
            );
        }

        $result = $this->transactions->run(
            function () use (
                $template,
                $version,
            ): string {
                $lockedTemplate =
                    ExamTemplate::query()
                        ->whereKey($template->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                $lockedVersion =
                    ExamTemplateVersion::query()
                        ->whereKey($version->id)
                        ->where(
                            'exam_template_id',
                            $lockedTemplate->id,
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $lockedVersion->status
                    === 'retired'
                ) {
                    return 'retired';
                }

                if (
                    $lockedVersion->status
                    === 'draft'
                ) {
                    ExamTemplateVersion::query()
                        ->whereKey(
                            $lockedVersion->id
                        )
                        ->update([
                            'status' =>
                                'published',
                            'updated_at' =>
                                now(),
                        ]);
                }

                ExamTemplate::query()
                    ->whereKey(
                        $lockedTemplate->id
                    )
                    ->update([
                        'published_version_id' =>
                            $lockedVersion->id,
                        'updated_at' => now(),
                    ]);

                return 'published';
            }
        );

        if ($result === 'retired') {
            return ApiResponse::error(
                'exam_template_version_retired',
                'A retired exam template version cannot be published again.',
                409,
            );
        }

        $version->refresh();
        $template->refresh();

        return ApiResponse::success([
            'version' =>
                $this->versionData($version),
            'template' =>
                $this->templateData(
                    $template->loadCount(
                        'versions'
                    )
                ),
        ]);
    }

    public function retireVersion(
        string $examTemplateVersionId,
    ): JsonResponse {
        $version = ExamTemplateVersion::query()
            ->whereKey($examTemplateVersionId)
            ->firstOrFail();

        $template = ExamTemplate::query()
            ->whereKey(
                $version->exam_template_id
            )
            ->firstOrFail();

        $curriculumVersion =
            CurriculumVersion::query()
                ->whereKey(
                    $version
                        ->curriculum_version_id
                )
                ->firstOrFail();

        if ($curriculumVersion->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Exam template versions may only be retired while the curriculum version is draft.',
                409,
            );
        }

        $result = $this->transactions->run(
            function () use (
                $template,
                $version,
            ): string {
                $lockedTemplate =
                    ExamTemplate::query()
                        ->whereKey($template->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                $lockedVersion =
                    ExamTemplateVersion::query()
                        ->whereKey($version->id)
                        ->where(
                            'exam_template_id',
                            $lockedTemplate->id,
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $lockedVersion->status
                    === 'retired'
                ) {
                    return 'retired';
                }

                if (
                    $lockedVersion->status
                    !== 'published'
                ) {
                    return 'not_published';
                }

                if (
                    $lockedTemplate
                        ->published_version_id
                    === $lockedVersion->id
                ) {
                    return 'current';
                }

                ExamTemplateVersion::query()
                    ->whereKey(
                        $lockedVersion->id
                    )
                    ->update([
                        'status' => 'retired',
                        'updated_at' => now(),
                    ]);

                return 'retired';
            }
        );

        if ($result === 'not_published') {
            return ApiResponse::error(
                'exam_template_version_not_published',
                'Only a published exam template version may be retired.',
                409,
            );
        }

        if ($result === 'current') {
            return ApiResponse::error(
                'exam_template_version_is_current',
                'The current published exam template version cannot be retired.',
                409,
            );
        }

        return ApiResponse::success(
            $this->versionData(
                $version->refresh()
            )
        );
    }

    private function templateData(
        ExamTemplate $template,
    ): array {
        return [
            'id' => $template->id,
            'curriculum_version_id' =>
                $template->curriculum_version_id,
            'name' => $template->name,
            'description' =>
                $template->description,
            'status' => $template->status,
            'published_version_id' =>
                $template
                    ->published_version_id,
            'versions_count' =>
                $template
                    ->versions_count ?? null,
            'created_at' =>
                $template
                    ->created_at?->toISOString(),
            'updated_at' =>
                $template
                    ->updated_at?->toISOString(),
        ];
    }

    private function versionData(
        ExamTemplateVersion $version,
    ): array {
        return [
            'id' => $version->id,
            'exam_template_id' =>
                $version->exam_template_id,
            'curriculum_version_id' =>
                $version
                    ->curriculum_version_id,
            'version_number' =>
                $version->version_number,
            'label' => $version->label,
            'status' => $version->status,
            'rules_payload' =>
                $version->rules_payload,
            'rules_schema_version' =>
                $version
                    ->rules_schema_version,
            'created_at' =>
                $version
                    ->created_at?->toISOString(),
            'updated_at' =>
                $version
                    ->updated_at?->toISOString(),
        ];
    }
}
