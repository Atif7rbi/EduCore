<?php

namespace App\Application\TeacherAuthoring;

use App\Application\Exceptions\TeacherAuthoringConflict;
use App\Application\Support\TransactionManager;
use App\Models\AssessmentItem;
use App\Models\AssessmentItemRevision;
use App\Models\AssessmentItemRevisionSkill;
use App\Models\CurriculumVersion;
use App\Models\Lesson;
use App\Models\LessonRevision;
use App\Models\LessonRevisionSkill;
use App\Models\SkillVersionPlacement;
use App\Models\Topic;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ManageTeacherLessonAssessmentAuthoring
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly LockActiveTeacherCurriculumAuthority $authority,
    ) {}

    public function createLesson(string $actor, string $assignment, string $curriculum, string $version, string $title, ?string $description, int $order): Lesson
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $title, $description, $order): Lesson {
            $v = $this->lockVersion($actor, $assignment, $curriculum, $version);
            $this->draft($v);

            return Lesson::query()->create(['curriculum_version_id' => $v->id, 'title' => $title, 'description' => $description, 'status' => 'draft', 'display_order' => $order, 'published_revision_id' => null]);
        });
    }

    public function updateLesson(string $actor, string $assignment, string $curriculum, string $version, string $lesson, string $title, ?string $description, int $order): Lesson
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $lesson, $title, $description, $order): Lesson {
            $v = $this->lockVersion($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            $l = $this->lesson($lesson, $v);
            $l->update(['title' => $title, 'description' => $description, 'display_order' => $order]);

            return $l->refresh();
        });
    }

    public function createLessonRevision(string $actor, string $assignment, string $curriculum, string $version, string $lesson, int $number, string $topic, array $content, int $schema): LessonRevision
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $lesson, $number, $topic, $content, $schema): LessonRevision {
            $v = $this->lockVersion($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            $l = $this->lesson($lesson, $v);
            $t = $this->topic($topic, $v);

            return LessonRevision::query()->create(['lesson_id' => $l->id, 'curriculum_version_id' => $v->id, 'revision_number' => $number, 'primary_topic_id' => $t->id, 'content_payload' => $content, 'content_schema_version' => $schema, 'released_at' => null]);
        });
    }

    public function updateLessonRevision(string $actor, string $assignment, string $curriculum, string $version, string $lesson, string $revision, int $number, string $topic, array $content, int $schema): LessonRevision
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $lesson, $revision, $number, $topic, $content, $schema): LessonRevision {
            $v = $this->lockVersion($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            $l = $this->lesson($lesson, $v);
            $r = $this->lessonRevision($revision, $l, $v);
            $this->unreleased($r->released_at, 'lesson_revision_released');
            $t = $this->topic($topic, $v);
            $r->update(['revision_number' => $number, 'primary_topic_id' => $t->id, 'content_payload' => $content, 'content_schema_version' => $schema]);

            return $r->refresh();
        });
    }

    public function createLessonRevisionSkill(string $actor, string $assignment, string $curriculum, string $version, string $lesson, string $revision, string $placement): LessonRevisionSkill
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $lesson, $revision, $placement): LessonRevisionSkill {
            $v = $this->lockVersion($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            $l = $this->lesson($lesson, $v);
            $r = $this->lessonRevision($revision, $l, $v);
            $this->unreleased($r->released_at, 'lesson_revision_released');
            $p = $this->placement($placement, $v);

            return LessonRevisionSkill::query()->create(['lesson_revision_id' => $r->id, 'skill_version_placement_id' => $p->id, 'curriculum_version_id' => $v->id]);
        });
    }

    public function deleteLessonRevisionSkill(string $actor, string $assignment, string $curriculum, string $version, string $lesson, string $revision, string $skill): void
    {
        $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $lesson, $revision, $skill): void {
            $v = $this->lockVersion($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            $l = $this->lesson($lesson, $v);
            $r = $this->lessonRevision($revision, $l, $v);
            $this->unreleased($r->released_at, 'lesson_revision_released');
            LessonRevisionSkill::query()->whereKey($skill)->where('lesson_revision_id', $r->id)->where('curriculum_version_id', $v->id)->lockForUpdate()->firstOrFail()->delete();
        });
    }

    public function createAssessment(string $actor, string $assignment, string $curriculum, string $version, string $type, ?string $label): AssessmentItem
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $type, $label): AssessmentItem {
            $v = $this->lockVersion($actor, $assignment, $curriculum, $version);
            $this->draft($v);

            return AssessmentItem::query()->create(['curriculum_version_id' => $v->id, 'item_type' => $type, 'internal_label' => $label, 'status' => 'draft', 'published_revision_id' => null]);
        });
    }

    public function updateAssessment(string $actor, string $assignment, string $curriculum, string $version, string $item, string $type, ?string $label): AssessmentItem
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $item, $type, $label): AssessmentItem {
            $v = $this->lockVersion($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            $i = $this->assessment($item, $v);
            if ($i->status !== 'draft') {
                throw new TeacherAuthoringConflict('assessment_item_not_draft', 'Only draft assessment items may be edited.');
            }
            $i->update(['item_type' => $type, 'internal_label' => $label]);

            return $i->refresh();
        });
    }

    public function createAssessmentRevision(string $actor, string $assignment, string $curriculum, string $version, string $item, int $number, ?string $topic, string $difficulty, array $content, int $contentSchema, array $scoring, int $scoringSchema): AssessmentItemRevision
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $item, $number, $topic, $difficulty, $content, $contentSchema, $scoring, $scoringSchema): AssessmentItemRevision {
            $v = $this->lockVersion($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            $i = $this->assessment($item, $v);
            if ($i->status !== 'draft') {
                throw new TeacherAuthoringConflict(
                    'assessment_item_not_draft',
                    'New revisions may only be authored for draft assessment items.',
                );
            }
            $t = $topic === null ? null : $this->topic($topic, $v);

            return AssessmentItemRevision::query()->create(['assessment_item_id' => $i->id, 'curriculum_version_id' => $v->id, 'revision_number' => $number, 'primary_topic_id' => $t?->id, 'difficulty' => $difficulty, 'content_payload' => $content, 'content_schema_version' => $contentSchema, 'scoring_payload' => $scoring, 'scoring_schema_version' => $scoringSchema, 'released_at' => null]);
        });
    }

    public function updateAssessmentRevision(string $actor, string $assignment, string $curriculum, string $version, string $item, string $revision, int $number, ?string $topic, string $difficulty, array $content, int $contentSchema, array $scoring, int $scoringSchema): AssessmentItemRevision
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $item, $revision, $number, $topic, $difficulty, $content, $contentSchema, $scoring, $scoringSchema): AssessmentItemRevision {
            $v = $this->lockVersion($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            $i = $this->assessment($item, $v);
            $r = $this->assessmentRevision($revision, $i, $v);
            $this->unreleased($r->released_at, 'assessment_item_revision_released');
            $t = $topic === null ? null : $this->topic($topic, $v);
            $r->update(['revision_number' => $number, 'primary_topic_id' => $t?->id, 'difficulty' => $difficulty, 'content_payload' => $content, 'content_schema_version' => $contentSchema, 'scoring_payload' => $scoring, 'scoring_schema_version' => $scoringSchema]);

            return $r->refresh();
        });
    }

    public function createAssessmentRevisionSkill(string $actor, string $assignment, string $curriculum, string $version, string $item, string $revision, string $placement, string $role): AssessmentItemRevisionSkill
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $item, $revision, $placement, $role): AssessmentItemRevisionSkill {
            $v = $this->lockVersion($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            $i = $this->assessment($item, $v);
            $r = $this->assessmentRevision($revision, $i, $v);
            $this->unreleased($r->released_at, 'assessment_item_revision_released');
            $p = $this->placement($placement, $v);

            return AssessmentItemRevisionSkill::query()->create(['assessment_item_revision_id' => $r->id, 'skill_version_placement_id' => $p->id, 'curriculum_version_id' => $v->id, 'role' => $role]);
        });
    }

    public function deleteAssessmentRevisionSkill(string $actor, string $assignment, string $curriculum, string $version, string $item, string $revision, string $skill): void
    {
        $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $item, $revision, $skill): void {
            $v = $this->lockVersion($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            $i = $this->assessment($item, $v);
            $r = $this->assessmentRevision($revision, $i, $v);
            $this->unreleased($r->released_at, 'assessment_item_revision_released');
            AssessmentItemRevisionSkill::query()->whereKey($skill)->where('assessment_item_revision_id', $r->id)->where('curriculum_version_id', $v->id)->lockForUpdate()->firstOrFail()->delete();
        });
    }

    public function lockLessonLifecycle(string $actor, string $assignment, string $curriculum, string $version, string $lesson, ?string $revision = null): string
    {
        $v = $this->lockVersion($actor, $assignment, $curriculum, $version);
        $this->draft($v);
        $l = $this->lesson($lesson, $v);
        if ($revision !== null) {
            $this->lessonRevision($revision, $l, $v);
        }

        return $v->id;
    }

    public function lockAssessmentLifecycle(string $actor, string $assignment, string $curriculum, string $version, string $item, ?string $revision = null): string
    {
        $v = $this->lockVersion($actor, $assignment, $curriculum, $version);
        $this->draft($v);
        $i = $this->assessment($item, $v);
        if ($revision !== null) {
            $this->assessmentRevision($revision, $i, $v);
        }

        return $v->id;
    }

    private function lockVersion(string $actor, string $assignment, string $curriculum, string $version): CurriculumVersion
    {
        $this->authority->execute($actor, $assignment, $curriculum);
        $v = CurriculumVersion::query()->whereKey($version)->lockForUpdate()->firstOrFail();
        if ($v->curriculum_id !== $curriculum) {
            $this->notFound(CurriculumVersion::class, $version);
        }

        return $v;
    }

    private function lesson(string $id, CurriculumVersion $v): Lesson
    {
        return Lesson::query()->whereKey($id)->where('curriculum_version_id', $v->id)->lockForUpdate()->firstOrFail();
    }

    private function assessment(string $id, CurriculumVersion $v): AssessmentItem
    {
        return AssessmentItem::query()->whereKey($id)->where('curriculum_version_id', $v->id)->lockForUpdate()->firstOrFail();
    }

    private function topic(string $id, CurriculumVersion $v): Topic
    {
        return Topic::query()->whereKey($id)->where('curriculum_version_id', $v->id)->lockForUpdate()->firstOrFail();
    }

    private function placement(string $id, CurriculumVersion $v): SkillVersionPlacement
    {
        return SkillVersionPlacement::query()->whereKey($id)->where('curriculum_version_id', $v->id)->lockForUpdate()->firstOrFail();
    }

    private function lessonRevision(string $id, Lesson $l, CurriculumVersion $v): LessonRevision
    {
        return LessonRevision::query()->whereKey($id)->where('lesson_id', $l->id)->where('curriculum_version_id', $v->id)->lockForUpdate()->firstOrFail();
    }

    private function assessmentRevision(string $id, AssessmentItem $i, CurriculumVersion $v): AssessmentItemRevision
    {
        return AssessmentItemRevision::query()->whereKey($id)->where('assessment_item_id', $i->id)->where('curriculum_version_id', $v->id)->lockForUpdate()->firstOrFail();
    }

    private function draft(CurriculumVersion $v): void
    {
        if ($v->status !== 'draft') {
            throw new TeacherAuthoringConflict('curriculum_version_not_draft', 'Teacher authoring requires a draft curriculum version.');
        }
    }

    private function unreleased(?CarbonImmutable $released, string $code): void
    {
        if ($released !== null) {
            throw new TeacherAuthoringConflict($code, 'Released revisions are immutable.');
        }
    }

    private function notFound(string $model, string $id): never
    {
        throw (new ModelNotFoundException)->setModel($model, [$id]);
    }
}
