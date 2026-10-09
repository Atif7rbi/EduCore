<?php

namespace App\Application\TeacherAuthoring;

use App\Application\Exceptions\TeacherAuthoringConflict;
use App\Application\Support\TransactionManager;
use App\Models\AssessmentItemRevision;
use App\Models\CurriculumVersion;
use App\Models\ExamTemplate;
use App\Models\ExamTemplateVersion;
use App\Models\Lesson;
use App\Models\PracticeActivity;
use App\Models\PracticeActivityItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

class ManageTeacherPracticeExamAuthoring
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly LockActiveTeacherCurriculumAuthority $authority,
    ) {}

    public function createPractice(string $actor, string $assignment, string $curriculum, string $version, ?string $lesson, string $name, ?string $description): PracticeActivity
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $lesson, $name, $description): PracticeActivity {
            $v = $this->version($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            if ($lesson !== null) {
                Lesson::query()->whereKey($lesson)->where('curriculum_version_id', $v->id)->lockForUpdate()->firstOrFail();
            }

            return PracticeActivity::query()->create(['curriculum_version_id' => $v->id, 'lesson_id' => $lesson, 'name' => $name, 'description' => $description, 'status' => 'archived']);
        });
    }

    public function updatePractice(string $actor, string $assignment, string $curriculum, string $version, string $practice, ?string $lesson, string $name, ?string $description): PracticeActivity
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $practice, $lesson, $name, $description): PracticeActivity {
            $v = $this->version($actor, $assignment, $curriculum, $version);
            $p = $this->practice($practice, $v);
            if ($p->status !== 'archived') {
                $this->conflict('practice_activity_not_archived', 'Only archived practice activities may be edited.');
            }
            if ($lesson !== null) {
                Lesson::query()->whereKey($lesson)->where('curriculum_version_id', $v->id)->lockForUpdate()->firstOrFail();
            }
            $p->update(['lesson_id' => $lesson, 'name' => $name, 'description' => $description]);

            return $p->refresh();
        });
    }

    public function setPracticeStatus(string $actor, string $assignment, string $curriculum, string $version, string $practice, string $status): PracticeActivity
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $practice, $status): PracticeActivity {
            $v = $this->version($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            $p = $this->practice($practice, $v);
            if ($status === 'active') {
                $items = PracticeActivityItem::query()->where('practice_activity_id', $p->id)->lockForUpdate()->get();
                if ($items->isEmpty()) {
                    $this->conflict('practice_activity_empty', 'An active practice activity requires at least one item.');
                }
                foreach ($items as $item) {
                    $r = $this->revision($item->assessment_item_revision_id, $v);
                    if ($r->released_at === null) {
                        $this->conflict('practice_activity_requires_released_revision', 'Active practice activities may only contain released assessment item revisions.');
                    }
                }
            }
            $p->update(['status' => $status]);

            return $p->refresh();
        });
    }

    /** @return array<int, PracticeActivityItem> */
    public function addPracticeItems(string $actor, string $assignment, string $curriculum, string $version, string $practice, array $revisionIds, int $displayOrder): array
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $practice, $revisionIds, $displayOrder): array {
            $v = $this->version($actor, $assignment, $curriculum, $version);
            $p = $this->practice($practice, $v);
            if ($p->status === 'active') {
                $this->draft($v);
            }
            $revisions = $this->revisions($revisionIds, $v);
            $created = [];
            foreach ($revisions as $offset => $r) {
                if ($r->released_at === null) {
                    $this->conflict('practice_activity_requires_released_revision', 'Practice activities may only contain released assessment item revisions.');
                }
                $created[] = PracticeActivityItem::query()->create(['id' => (string) Str::uuid(), 'practice_activity_id' => $p->id, 'assessment_item_revision_id' => $r->id, 'assessment_item_id' => $r->assessment_item_id, 'curriculum_version_id' => $v->id, 'display_order' => $displayOrder + $offset]);
            }

            return $created;
        });
    }

    public function removePracticeItem(string $actor, string $assignment, string $curriculum, string $version, string $practice, string $membership): void
    {
        $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $practice, $membership): void {
            $v = $this->version($actor, $assignment, $curriculum, $version);
            $p = $this->practice($practice, $v);
            if ($p->status === 'active') {
                $this->draft($v);
                $memberships = PracticeActivityItem::query()
                    ->where('practice_activity_id', $p->id)
                    ->where('curriculum_version_id', $v->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if ($memberships->count() <= 1) {
                    $this->conflict('practice_activity_requires_item', 'The last item cannot be removed from an active practice activity.');
                }

                $membership = $memberships->firstWhere('id', $membership)
                    ?? throw (new ModelNotFoundException)->setModel(
                        PracticeActivityItem::class,
                        [$membership],
                    );

                $membership->delete();

                return;
            }

            PracticeActivityItem::query()->whereKey($membership)->where('practice_activity_id', $p->id)->where('curriculum_version_id', $v->id)->lockForUpdate()->firstOrFail()->delete();
        });
    }

    public function createTemplate(string $actor, string $assignment, string $curriculum, string $version, string $name, ?string $description): ExamTemplate
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $name, $description): ExamTemplate {
            $v = $this->version($actor, $assignment, $curriculum, $version);
            $this->draft($v);

            return ExamTemplate::query()->create(['curriculum_version_id' => $v->id, 'name' => $name, 'description' => $description, 'status' => 'active', 'published_version_id' => null]);
        });
    }

    public function updateTemplate(string $actor, string $assignment, string $curriculum, string $version, string $template, string $name, ?string $description): ExamTemplate
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $template, $name, $description): ExamTemplate {
            $v = $this->version($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            $t = $this->template($template, $v);
            if ($t->status !== 'active') {
                $this->conflict('exam_template_not_active', 'Only active exam templates may be edited.');
            }
            $t->update(['name' => $name, 'description' => $description]);

            return $t->refresh();
        });
    }

    public function setTemplateStatus(string $actor, string $assignment, string $curriculum, string $version, string $template, string $status): ExamTemplate
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $template, $status): ExamTemplate {
            $v = $this->version($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            $t = $this->template($template, $v);
            $t->update(['status' => $status]);

            return $t->refresh();
        });
    }

    public function createTemplateVersion(string $actor, string $assignment, string $curriculum, string $version, string $template, int $number, ?string $label, array $rules, int $schema): ExamTemplateVersion
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $template, $number, $label, $rules, $schema): ExamTemplateVersion {
            $v = $this->version($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            $t = $this->template($template, $v);
            if ($t->status !== 'active') {
                $this->conflict('exam_template_not_active', 'New versions may only be added to active exam templates.');
            }

            return ExamTemplateVersion::query()->create(['exam_template_id' => $t->id, 'curriculum_version_id' => $v->id, 'version_number' => $number, 'label' => $label, 'status' => 'draft', 'rules_payload' => (object) $rules, 'rules_schema_version' => $schema]);
        });
    }

    public function updateTemplateVersion(string $actor, string $assignment, string $curriculum, string $version, string $template, string $templateVersion, ?string $label, array $rules, int $schema): ExamTemplateVersion
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $template, $templateVersion, $label, $rules, $schema): ExamTemplateVersion {
            $v = $this->version($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            $t = $this->template($template, $v);
            $tv = $this->templateVersion($templateVersion, $t, $v);
            if ($t->status !== 'active') {
                $this->conflict('exam_template_not_active', 'Only versions of active templates may be edited.');
            }
            if ($tv->status !== 'draft') {
                $this->conflict('exam_template_version_not_draft', 'Only draft exam template versions may be edited.');
            }
            $tv->update(['label' => $label, 'rules_payload' => (object) $rules, 'rules_schema_version' => $schema]);

            return $tv->refresh();
        });
    }

    public function publishTemplateVersion(string $actor, string $assignment, string $curriculum, string $version, string $template, string $templateVersion): ExamTemplateVersion
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $template, $templateVersion): ExamTemplateVersion {
            $v = $this->version($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            $t = $this->template($template, $v);
            $tv = $this->templateVersion($templateVersion, $t, $v);
            if ($t->status !== 'active') {
                $this->conflict('exam_template_not_active', 'Only versions of active exam templates may be published.');
            }
            if ($tv->status === 'retired') {
                $this->conflict('exam_template_version_retired', 'A retired exam template version cannot be published again.');
            }
            if ($tv->status === 'draft') {
                $tv->update(['status' => 'published']);
            }
            $t->update(['published_version_id' => $tv->id]);

            return $tv->refresh();
        });
    }

    public function retireTemplateVersion(string $actor, string $assignment, string $curriculum, string $version, string $template, string $templateVersion): ExamTemplateVersion
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $template, $templateVersion): ExamTemplateVersion {
            $v = $this->version($actor, $assignment, $curriculum, $version);
            $this->draft($v);
            $t = $this->template($template, $v);
            $tv = $this->templateVersion($templateVersion, $t, $v);
            if ($tv->status !== 'published') {
                $this->conflict('exam_template_version_not_published', 'Only a published exam template version may be retired.');
            }
            if ($t->published_version_id === $tv->id) {
                $this->conflict('exam_template_version_is_current', 'The current published exam template version cannot be retired.');
            }
            $tv->update(['status' => 'retired']);

            return $tv->refresh();
        });
    }

    /** @return array<int, array{assessment_item_revision_id:string,assessment_item_id:string}> */
    public function generationItems(string $actor, string $assignment, string $curriculum, string $version, string $template, string $templateVersion, array $revisionIds): array
    {
        return $this->transactions->run(function () use ($actor, $assignment, $curriculum, $version, $template, $templateVersion, $revisionIds): array {
            $v = $this->version($actor, $assignment, $curriculum, $version);
            if ($v->status === 'retired') {
                $this->conflict('curriculum_version_retired', 'Exam generations cannot be built for retired curriculum versions.');
            }
            $t = $this->template($template, $v);
            $tv = $this->templateVersion($templateVersion, $t, $v);
            if ($tv->status !== 'published') {
                $this->conflict('exam_template_version_not_published', 'Exam generations require a published exam template version.');
            }

            return array_map(fn (AssessmentItemRevision $r): array => ['assessment_item_revision_id' => $r->id, 'assessment_item_id' => $r->assessment_item_id], $this->releasedRevisions($revisionIds, $v));
        });
    }

    public function lockGenerationAuthority(string $actor, string $assignment, string $curriculum, string $version, string $template, string $templateVersion, array $revisionIds): string
    {
        $this->generationItems($actor, $assignment, $curriculum, $version, $template, $templateVersion, $revisionIds);

        return $version;
    }

    private function version(string $actor, string $assignment, string $curriculum, string $version): CurriculumVersion
    {
        $this->authority->execute($actor, $assignment, $curriculum);
        $v = CurriculumVersion::query()->whereKey($version)->lockForUpdate()->firstOrFail();
        if ($v->curriculum_id !== $curriculum) {
            throw (new ModelNotFoundException)->setModel(CurriculumVersion::class, [$version]);
        }

        return $v;
    }

    private function practice(string $id, CurriculumVersion $v): PracticeActivity
    {
        return PracticeActivity::query()->whereKey($id)->where('curriculum_version_id', $v->id)->lockForUpdate()->firstOrFail();
    }

    private function template(string $id, CurriculumVersion $v): ExamTemplate
    {
        return ExamTemplate::query()->whereKey($id)->where('curriculum_version_id', $v->id)->lockForUpdate()->firstOrFail();
    }

    private function templateVersion(string $id, ExamTemplate $t, CurriculumVersion $v): ExamTemplateVersion
    {
        return ExamTemplateVersion::query()->whereKey($id)->where('exam_template_id', $t->id)->where('curriculum_version_id', $v->id)->lockForUpdate()->firstOrFail();
    }

    /** @return array<int, AssessmentItemRevision> */
    private function revisions(array $ids, CurriculumVersion $v): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        if ($ids === []) {
            $this->conflict('assessment_revisions_required', 'At least one assessment item revision is required.');
        } $rows = AssessmentItemRevision::query()->whereIn('id', $ids)->where('curriculum_version_id', $v->id)->orderBy('id')->lockForUpdate()->get();
        if ($rows->count() !== count($ids)) {
            throw (new ModelNotFoundException)->setModel(AssessmentItemRevision::class, $ids);
        }

        return $rows->all();
    }

    /** @return array<int, AssessmentItemRevision> */
    private function releasedRevisions(array $ids, CurriculumVersion $v): array
    {
        $rows = $this->revisions($ids, $v);
        foreach ($rows as $row) {
            if ($row->released_at === null) {
                $this->conflict('assessment_item_revision_not_released', 'Only released assessment item revisions may be selected.');
            }
        }

        return $rows;
    }

    private function draft(CurriculumVersion $v): void
    {
        if ($v->status !== 'draft') {
            $this->conflict('curriculum_version_not_draft', 'Teacher authoring requires a draft curriculum version.');
        }
    }

    private function revision(string $id, CurriculumVersion $v): AssessmentItemRevision
    {
        return AssessmentItemRevision::query()->whereKey($id)->where('curriculum_version_id', $v->id)->lockForUpdate()->firstOrFail();
    }

    private function conflict(string $code, string $message): never
    {
        throw new TeacherAuthoringConflict($code, $message);
    }
}
