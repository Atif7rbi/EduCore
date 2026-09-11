<?php

namespace App\Application\Attempt;

use App\Application\Support\TransactionManager;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\AssessmentItemRevision;
use App\Models\CurriculumVersion;
use App\Models\ExamGeneration;
use App\Models\ExamTemplateVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BuildExamAttempt
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function execute(
        string $learnerProfileId,
        string $examGenerationId,
    ): Attempt {
        return $this->transactions->run(
            function () use (
                $learnerProfileId,
                $examGenerationId,
            ): Attempt {
                $generationIdentity = ExamGeneration::query()
                    ->whereKey($examGenerationId)
                    ->firstOrFail([
                        'id',
                        'curriculum_version_id',
                        'exam_template_version_id',
                    ]);

                /*
                 * Learner-facing eligibility must be revalidated
                 * inside this transaction after acquiring the
                 * lifecycle rows that can make the generation
                 * unavailable to a new learner Attempt.
                 *
                 * Lock order:
                 * CurriculumVersion
                 * -> ExamTemplateVersion
                 * -> ExamGeneration
                 */
                $curriculumVersion = CurriculumVersion::query()
                    ->whereKey(
                        $generationIdentity->curriculum_version_id
                    )
                    ->where('status', 'published')
                    ->lockForUpdate()
                    ->firstOrFail();

                $templateVersion = ExamTemplateVersion::query()
                    ->whereKey(
                        $generationIdentity->exam_template_version_id
                    )
                    ->where(
                        'curriculum_version_id',
                        $curriculumVersion->id,
                    )
                    ->where('status', 'published')
                    ->lockForUpdate()
                    ->firstOrFail();

                $generation = ExamGeneration::query()
                    ->whereKey($examGenerationId)
                    ->where(
                        'curriculum_version_id',
                        $curriculumVersion->id,
                    )
                    ->where(
                        'exam_template_version_id',
                        $templateVersion->id,
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $attempt = Attempt::query()->create([
                    'id' => (string) Str::uuid(),
                    'learner_profile_id' => $learnerProfileId,
                    'exam_generation_id' => $generation->id,
                    'practice_activity_id' => null,
                    'curriculum_version_id' => $generation->curriculum_version_id,
                    'status' => 'in_progress',
                    'started_at' => null,
                    'finalized_at' => null,
                ]);

                $generationItems = $generation->items()
                    ->orderBy('selection_position')
                    ->lockForUpdate()
                    ->get();

                foreach ($generationItems as $generationItem) {
                    $revision = AssessmentItemRevision::query()
                        ->whereKey($generationItem->assessment_item_revision_id)
                        ->firstOrFail();

                    $attemptItem = AttemptItem::query()->create([
                        'id' => (string) Str::uuid(),
                        'attempt_id' => $attempt->id,
                        'assessment_item_revision_id' => $revision->id,
                        'assessment_item_id' => $generationItem->assessment_item_id,
                        'curriculum_version_id' => $generation->curriculum_version_id,
                        'exam_generation_id' => $generation->id,
                        'exam_generation_item_id' => $generationItem->id,
                        'presentation_position' => $generationItem->selection_position,
                        'presented_payload' => $revision->content_payload,
                        'presented_schema_version' => $revision->content_schema_version,
                        'scoring_snapshot' => $revision->scoring_payload,
                        'scoring_schema_version' => $revision->scoring_schema_version,
                        'primary_topic_id' => $revision->primary_topic_id,
                    ]);

                    $skills = DB::table('assessment_item_revision_skills as airs')
                        ->join(
                            'skill_version_placements as svp',
                            'svp.id',
                            '=',
                            'airs.skill_version_placement_id'
                        )
                        ->where(
                            'airs.assessment_item_revision_id',
                            $revision->id
                        )
                        ->orderBy('svp.skill_id')
                        ->get([
                            'svp.skill_id',
                            'airs.role',
                        ]);

                    foreach ($skills as $skill) {
                        DB::table('attempt_item_classification_skills')->insert([
                            'id' => (string) Str::uuid(),
                            'attempt_item_id' => $attemptItem->id,
                            'skill_id' => $skill->skill_id,
                            'role' => $skill->role,
                            'created_at' => CarbonImmutable::now('UTC'),
                        ]);
                    }

                    DB::table('attempt_responses')->insert([
                        'id' => (string) Str::uuid(),
                        'attempt_item_id' => $attemptItem->id,
                        'response_payload' => null,
                        'answer_change_count' => 0,
                        'time_spent_ms' => 0,
                        'original_is_correct' => null,
                        'created_at' => CarbonImmutable::now('UTC'),
                        'updated_at' => null,
                    ]);
                }

                DB::table('attempts')
                    ->where('id', $attempt->id)
                    ->update([
                        'started_at' => CarbonImmutable::now('UTC'),
                        'updated_at' => CarbonImmutable::now('UTC'),
                    ]);

                return $attempt->refresh();
            }
        );
    }
}
