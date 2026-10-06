<?php

namespace App\Application\TeacherAuthoring;

use App\Application\Exceptions\TeacherAuthoringConflict;
use App\Application\Support\TransactionManager;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\Skill;
use App\Models\SkillHomeTopic;
use App\Models\SkillVersionPlacement;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ManageTeacherCurriculumAuthoring
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly LockActiveTeacherCurriculumAuthority $authority,
    ) {}

    public function updateCurriculum(
        string $actorUserId,
        string $assignmentId,
        string $curriculumId,
        string $name,
    ): Curriculum {
        return $this->transactions->run(
            function () use ($actorUserId, $assignmentId, $curriculumId, $name): Curriculum {
                $this->authority->execute($actorUserId, $assignmentId, $curriculumId);

                $curriculum = Curriculum::query()->whereKey($curriculumId)->firstOrFail();
                $curriculum->name = $name;
                $curriculum->save();

                return $curriculum->refresh();
            },
        );
    }

    public function createVersion(
        string $actorUserId,
        string $assignmentId,
        string $curriculumId,
        int $versionNumber,
        string $label,
    ): CurriculumVersion {
        return $this->transactions->run(
            function () use ($actorUserId, $assignmentId, $curriculumId, $versionNumber, $label): CurriculumVersion {
                $this->authority->execute($actorUserId, $assignmentId, $curriculumId);

                return CurriculumVersion::query()->create([
                    'curriculum_id' => $curriculumId,
                    'version_number' => $versionNumber,
                    'label' => $label,
                    'status' => 'draft',
                ]);
            },
        );
    }

    public function updateVersion(
        string $actorUserId,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
        int $versionNumber,
        string $label,
    ): CurriculumVersion {
        return $this->transactions->run(
            function () use ($actorUserId, $assignmentId, $curriculumId, $versionId, $versionNumber, $label): CurriculumVersion {
                $version = $this->lockVersion($actorUserId, $assignmentId, $curriculumId, $versionId);
                $this->requireDraft($version);

                $version->version_number = $versionNumber;
                $version->label = $label;
                $version->save();

                return $version->refresh();
            },
        );
    }

    public function createTopic(
        string $actorUserId,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
        string $name,
        int $displayOrder,
    ): Topic {
        return $this->transactions->run(
            function () use ($actorUserId, $assignmentId, $curriculumId, $versionId, $name, $displayOrder): Topic {
                $version = $this->lockVersion($actorUserId, $assignmentId, $curriculumId, $versionId);
                $this->requireDraft($version);

                return Topic::query()->create([
                    'curriculum_version_id' => $version->id,
                    'name' => $name,
                    'display_order' => $displayOrder,
                ]);
            },
        );
    }

    public function updateTopic(
        string $actorUserId,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
        string $topicId,
        string $name,
        int $displayOrder,
    ): Topic {
        return $this->transactions->run(
            function () use ($actorUserId, $assignmentId, $curriculumId, $versionId, $topicId, $name, $displayOrder): Topic {
                $version = $this->lockVersion($actorUserId, $assignmentId, $curriculumId, $versionId);
                $this->requireDraft($version);

                $topic = Topic::query()
                    ->whereKey($topicId)
                    ->where('curriculum_version_id', $version->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $topic->name = $name;
                $topic->display_order = $displayOrder;
                $topic->save();

                return $topic->refresh();
            },
        );
    }

    public function createPlacement(
        string $actorUserId,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
        string $skillId,
    ): SkillVersionPlacement {
        return $this->transactions->run(
            function () use ($actorUserId, $assignmentId, $curriculumId, $versionId, $skillId): SkillVersionPlacement {
                $version = $this->lockVersion($actorUserId, $assignmentId, $curriculumId, $versionId);
                $this->requireDraft($version);

                $skill = Skill::query()->whereKey($skillId)->lockForUpdate()->firstOrFail();

                return SkillVersionPlacement::query()->create([
                    'skill_id' => $skill->id,
                    'curriculum_version_id' => $version->id,
                ]);
            },
        );
    }

    public function deletePlacement(
        string $actorUserId,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
        string $placementId,
    ): void {
        $this->transactions->run(
            function () use ($actorUserId, $assignmentId, $curriculumId, $versionId, $placementId): void {
                $version = $this->lockVersion($actorUserId, $assignmentId, $curriculumId, $versionId);
                $this->requireDraft($version);

                $placement = SkillVersionPlacement::query()
                    ->whereKey($placementId)
                    ->where('curriculum_version_id', $version->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $usedByLesson = DB::table('lesson_revision_skills')
                    ->where('skill_version_placement_id', $placement->id)
                    ->exists();
                $usedByAssessment = DB::table('assessment_item_revision_skills')
                    ->where('skill_version_placement_id', $placement->id)
                    ->exists();

                if ($usedByLesson || $usedByAssessment) {
                    throw new TeacherAuthoringConflict(
                        'skill_placement_in_use',
                        'This skill placement is used by authored content.',
                    );
                }

                $homeTopicIds = $this->normalizedSortedIds(
                    SkillHomeTopic::query()
                        ->where('placement_id', $placement->id)
                        ->pluck('id')
                        ->all(),
                );

                if ($homeTopicIds !== []) {
                    SkillHomeTopic::query()
                        ->whereIn('id', $homeTopicIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    SkillHomeTopic::query()->whereIn('id', $homeTopicIds)->delete();
                }

                $placement->delete();
            },
        );
    }

    public function createHomeTopic(
        string $actorUserId,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
        string $placementId,
        string $topicId,
    ): SkillHomeTopic {
        return $this->transactions->run(
            function () use ($actorUserId, $assignmentId, $curriculumId, $versionId, $placementId, $topicId): SkillHomeTopic {
                $version = $this->lockVersion($actorUserId, $assignmentId, $curriculumId, $versionId);
                $this->requireDraft($version);

                $placement = SkillVersionPlacement::query()
                    ->whereKey($placementId)
                    ->where('curriculum_version_id', $version->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $topic = Topic::query()
                    ->whereKey($topicId)
                    ->where('curriculum_version_id', $version->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                return SkillHomeTopic::query()->create([
                    'placement_id' => $placement->id,
                    'topic_id' => $topic->id,
                    'curriculum_version_id' => $version->id,
                ]);
            },
        );
    }

    public function deleteHomeTopic(
        string $actorUserId,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
        string $placementId,
        string $homeTopicId,
    ): void {
        $this->transactions->run(
            function () use ($actorUserId, $assignmentId, $curriculumId, $versionId, $placementId, $homeTopicId): void {
                $version = $this->lockVersion($actorUserId, $assignmentId, $curriculumId, $versionId);
                $this->requireDraft($version);

                $placement = SkillVersionPlacement::query()
                    ->whereKey($placementId)
                    ->where('curriculum_version_id', $version->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $homeTopic = SkillHomeTopic::query()
                    ->whereKey($homeTopicId)
                    ->where('placement_id', $placement->id)
                    ->where('curriculum_version_id', $version->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $homeTopic->delete();
            },
        );
    }

    public function lockLifecycleAuthority(
        string $actorUserId,
        string $assignmentId,
        string $curriculumId,
    ): string {
        return $this->authority->execute($actorUserId, $assignmentId, $curriculumId)->curriculumId;
    }

    private function lockVersion(
        string $actorUserId,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
    ): CurriculumVersion {
        $this->authority->execute($actorUserId, $assignmentId, $curriculumId);

        $version = CurriculumVersion::query()
            ->whereKey($versionId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($version->curriculum_id !== $curriculumId) {
            $this->notFound(CurriculumVersion::class, $versionId);
        }

        return $version;
    }

    private function requireDraft(CurriculumVersion $version): void
    {
        if ($version->status === 'draft') {
            return;
        }

        throw new TeacherAuthoringConflict(
            'curriculum_version_not_draft',
            'Structural authoring requires a draft curriculum version.',
        );
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function normalizedSortedIds(array $ids): array
    {
        $normalized = array_values(array_unique(array_map(
            static fn (mixed $id): string => strtolower((string) $id),
            $ids,
        )));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function notFound(string $model, string $id): never
    {
        throw (new ModelNotFoundException)->setModel($model, [$id]);
    }
}
