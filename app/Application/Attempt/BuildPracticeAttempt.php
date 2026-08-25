<?php

namespace App\Application\Attempt;

use App\Application\Support\TransactionManager;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\AssessmentItemRevision;
use App\Models\PracticeActivity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BuildPracticeAttempt
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function execute(
        string $learnerProfileId,
        string $practiceActivityId,
    ): Attempt {
        return $this->transactions->run(
            function () use (
                $learnerProfileId,
                $practiceActivityId,
            ): Attempt {
                $activity = PracticeActivity::query()
                    ->whereKey($practiceActivityId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $attempt = Attempt::query()->create([
                    'id' => (string) Str::uuid(),
                    'learner_profile_id' => $learnerProfileId,
                    'exam_generation_id' => null,
                    'practice_activity_id' => $activity->id,
                    'curriculum_version_id' => $activity->curriculum_version_id,
                    'status' => 'in_progress',
                    'started_at' => null,
                    'finalized_at' => null,
                ]);

                $activityItems = DB::table('practice_activity_items')
                    ->where('practice_activity_id', $activity->id)
                    ->orderBy('display_order')
                    ->lockForUpdate()
                    ->get();

                foreach ($activityItems as $activityItem) {
                    $revision = AssessmentItemRevision::query()
                        ->whereKey($activityItem->assessment_item_revision_id)
                        ->firstOrFail();

                    $attemptItem = AttemptItem::query()->create([
                        'id' => (string) Str::uuid(),
                        'attempt_id' => $attempt->id,
                        'assessment_item_revision_id' => $revision->id,
                        'assessment_item_id' => $activityItem->assessment_item_id,
                        'curriculum_version_id' => $activity->curriculum_version_id,
                        'exam_generation_id' => null,
                        'exam_generation_item_id' => null,
                        'presentation_position' => $activityItem->display_order,
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
