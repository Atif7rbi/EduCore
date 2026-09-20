<?php

namespace App\Http\Controllers\Api\Read;

use App\Application\Authorization\FilterActiveLearnerCurriculumRead;
use App\Application\Identity\AuthenticatedLearner;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurriculumReadController extends Controller
{
    public function index(
        Request $request,
        AuthenticatedLearner $learnerContext,
        FilterActiveLearnerCurriculumRead $access,
    ): JsonResponse {
        $learner = $learnerContext->resolve(
            $request->user()
        );

        $curricula = $access->curricula(
            Curriculum::query(),
            $learner->id,
        )
            ->join(
                'subjects',
                'subjects.id',
                '=',
                'curricula.subject_id'
            )
            ->whereHas(
                'versions',
                fn ($query) => $query
                    ->where('status', 'published')
            )
            ->with([
                'subject',
                'versions' => fn ($query) => $query
                    ->where('status', 'published')
                    ->orderBy('version_number')
                    ->orderBy('id'),
            ])
            ->orderBy('subjects.name')
            ->orderBy('curricula.name')
            ->orderBy('curricula.id')
            ->select('curricula.*')
            ->get();

        return ApiResponse::success(
            $curricula
                ->map(fn (Curriculum $curriculum): array => [
                    'subject' => [
                        'id' => $curriculum->subject->id,
                        'name' => $curriculum->subject->name,
                    ],
                    'curriculum' => [
                        'id' => $curriculum->id,
                        'name' => $curriculum->name,
                    ],
                    'published_versions' => $curriculum->versions
                        ->map(fn (CurriculumVersion $version): array => [
                            'id' => $version->id,
                            'version_number' => $version->version_number,
                            'label' => $version->label,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all()
        );
    }

    public function showVersion(
        string $curriculumVersionId,
        Request $request,
        AuthenticatedLearner $learnerContext,
        FilterActiveLearnerCurriculumRead $access,
    ): JsonResponse {
        $learner = $learnerContext->resolve(
            $request->user()
        );

        $version = $access->versions(
            CurriculumVersion::query(),
            $learner->id,
        )
            ->where('status', 'published')
            ->with([
                'topics' => fn ($query) => $query
                    ->orderBy('display_order'),
            ])
            ->findOrFail($curriculumVersionId);

        return ApiResponse::success([
            'id' => $version->id,
            'curriculum_id' => $version->curriculum_id,
            'version_number' => $version->version_number,
            'label' => $version->label,
            'status' => $version->status,
            'topics' => $version->topics
                ->map(fn ($topic): array => [
                    'id' => $topic->id,
                    'name' => $topic->name,
                    'display_order' => $topic->display_order,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function lessons(
        string $curriculumVersionId,
        Request $request,
        AuthenticatedLearner $learnerContext,
        FilterActiveLearnerCurriculumRead $access,
    ): JsonResponse {
        $learner = $learnerContext->resolve(
            $request->user()
        );

        $access->versions(
            CurriculumVersion::query(),
            $learner->id,
        )
            ->where('status', 'published')
            ->findOrFail($curriculumVersionId);

        $lessons = Lesson::query()
            ->where(
                'curriculum_version_id',
                $curriculumVersionId
            )
            ->whereHas(
                'curriculumVersion',
                function ($query) use (
                    $access,
                    $learner,
                ): void {
                    $access->versions(
                        $query,
                        $learner->id,
                    )->where(
                        'status',
                        'published',
                    );
                },
            )
            ->where('status', 'published')
            ->whereNotNull('published_revision_id')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(
            $lessons
                ->map(fn (Lesson $lesson): array => [
                    'id' => $lesson->id,
                    'curriculum_version_id' => $lesson->curriculum_version_id,
                    'title' => $lesson->title,
                    'description' => $lesson->description,
                    'status' => $lesson->status,
                    'display_order' => $lesson->display_order,
                    'published_revision_id' => $lesson->published_revision_id,
                ])
                ->values()
                ->all()
        );
    }
}
