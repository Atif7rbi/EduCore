<?php

namespace App\Http\Controllers\Api\Admin;

use App\Application\Support\TransactionManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCurriculumRequest;
use App\Http\Requests\Admin\StoreCurriculumVersionRequest;
use App\Http\Requests\Admin\StoreSubjectRequest;
use App\Http\Requests\Admin\UpdateCurriculumRequest;
use App\Http\Requests\Admin\UpdateCurriculumVersionRequest;
use App\Http\Requests\Admin\UpdateSubjectRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;

class AdminCurriculumManagementController extends Controller
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {}

    public function storeSubject(
        StoreSubjectRequest $request,
    ): JsonResponse {
        /*
         * Compatibility path only.
         *
         * New canonical Subjects are platform-owned reference data.
         * This endpoint may create only a legacy/non-canonical Subject
         * because Subject::$fillable does not expose canonical metadata.
         */
        $subject = $this->transactions->run(
            fn (): Subject => Subject::query()->create(
                $request->validated()
            )
        );

        return ApiResponse::success(
            $this->subjectData($subject->refresh()),
            201,
        );
    }

    public function updateSubject(
        UpdateSubjectRequest $request,
        string $subjectId,
    ): JsonResponse {
        $subject = Subject::query()
            ->whereKey($subjectId)
            ->firstOrFail();

        $requestedName = $request->validated('name');

        if (
            $subject->isCanonical()
            && $requestedName !== $subject->name
        ) {
            return ApiResponse::error(
                'canonical_subject_immutable',
                'Canonical subjects cannot be renamed.',
                409,
            );
        }

        $this->transactions->run(
            fn (): bool => $subject->update([
                'name' => $requestedName,
            ])
        );

        return ApiResponse::success(
            $this->subjectData($subject->refresh())
        );
    }

    public function storeCurriculum(
        StoreCurriculumRequest $request,
        string $subjectId,
    ): JsonResponse {
        $subject = Subject::query()
            ->whereKey($subjectId)
            ->firstOrFail();

        if (! $subject->isAvailableForNewContent()) {
            return ApiResponse::error(
                'subject_not_available_for_new_content',
                'The selected subject is not available for new content.',
                409,
            );
        }

        $curriculum = $this->transactions->run(
            fn (): Curriculum => Curriculum::query()->create([
                'subject_id' => $subject->id,
                'education_stage_id' => $request->validated(
                    'education_stage_id'
                ),
                'name' => $request->validated('name'),
            ])
        );

        return ApiResponse::success(
            $this->curriculumData($curriculum),
            201,
        );
    }

    public function updateCurriculum(
        UpdateCurriculumRequest $request,
        string $curriculumId,
    ): JsonResponse {
        $curriculum = Curriculum::query()
            ->whereKey($curriculumId)
            ->firstOrFail();

        $this->transactions->run(
            fn (): bool => $curriculum->update(
                $request->validated()
            )
        );

        return ApiResponse::success(
            $this->curriculumData(
                $curriculum->refresh()
            )
        );
    }

    public function storeVersion(
        StoreCurriculumVersionRequest $request,
        string $curriculumId,
    ): JsonResponse {
        $curriculum = Curriculum::query()
            ->whereKey($curriculumId)
            ->firstOrFail();

        $version = $this->transactions->run(
            fn (): CurriculumVersion => CurriculumVersion::query()->create([
                'curriculum_id' => $curriculum->id,
                'version_number' => $request->validated('version_number'),
                'label' => $request->validated('label'),
                'status' => 'draft',
            ])
        );

        return ApiResponse::success(
            $this->versionData($version),
            201,
        );
    }

    public function updateVersion(
        UpdateCurriculumVersionRequest $request,
        string $curriculumVersionId,
    ): JsonResponse {
        $version = CurriculumVersion::query()
            ->whereKey($curriculumVersionId)
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Only draft curriculum versions may be edited.',
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

    private function subjectData(
        Subject $subject,
    ): array {
        $curriculaCount = $subject->getAttribute(
            'curricula_count'
        );

        if ($curriculaCount === null) {
            $curriculaCount = $subject
                ->curricula()
                ->count();
        }

        return [
            'id' => $subject->id,
            'code' => $subject->code,
            'name' => $subject->name,
            'icon_key' => $subject->icon_key,
            'thumbnail_key' => $subject->thumbnail_key,
            'sort_order' => (int) $subject->sort_order,
            'status' => $subject->status,
            'curricula_count' => (int) $curriculaCount,
            'created_at' => $subject->created_at?->toISOString(),
            'updated_at' => $subject->updated_at?->toISOString(),
        ];
    }

    private function curriculumData(
        Curriculum $curriculum,
    ): array {
        return [
            'id' => $curriculum->id,
            'subject_id' => $curriculum->subject_id,
            'education_stage_id' => $curriculum->education_stage_id,
            'name' => $curriculum->name,
            'created_at' => $curriculum->created_at?->toISOString(),
            'updated_at' => $curriculum->updated_at?->toISOString(),
        ];
    }

    private function versionData(
        CurriculumVersion $version,
    ): array {
        return [
            'id' => $version->id,
            'curriculum_id' => $version->curriculum_id,
            'version_number' => $version->version_number,
            'label' => $version->label,
            'status' => $version->status,
            'created_at' => $version->created_at?->toISOString(),
            'updated_at' => $version->updated_at?->toISOString(),
        ];
    }
}
