<?php

namespace Tests\Unit;

use App\Application\Analytics\ProjectAttemptSkillEvidence;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\AttemptItemClassificationSkill;
use App\Models\AttemptResponse;
use App\Models\RegradeCorrection;
use Illuminate\Database\Eloquent\Collection;
use LogicException;
use PHPUnit\Framework\TestCase;

class ProjectAttemptSkillEvidenceTest extends TestCase
{
    public function test_single_primary_correct_answer_projects_individual_evidence(): void
    {
        $attempt = $this->attempt([
            $this->item(
                'item-1',
                true,
                [
                    ['skill-a', 'primary'],
                ],
            ),
        ]);

        $evidence =
            (new ProjectAttemptSkillEvidence())
                ->execute($attempt);

        $this->assertCount(1, $evidence);

        $this->assertSame(
            'single_primary',
            $evidence[0]['type']
        );

        $this->assertSame(
            'skill-a',
            $evidence[0]['skill_id']
        );

        $this->assertSame(
            1,
            $evidence[0][
                'single_primary_answered_count'
            ]
        );

        $this->assertSame(
            1,
            $evidence[0][
                'single_primary_correct_count'
            ]
        );
    }

    public function test_single_primary_incorrect_answer_projects_answered_without_correct(): void
    {
        $attempt = $this->attempt([
            $this->item(
                'item-1',
                false,
                [
                    ['skill-a', 'primary'],
                ],
            ),
        ]);

        $evidence =
            (new ProjectAttemptSkillEvidence())
                ->execute($attempt);

        $this->assertSame(
            1,
            $evidence[0][
                'single_primary_answered_count'
            ]
        );

        $this->assertSame(
            0,
            $evidence[0][
                'single_primary_correct_count'
            ]
        );
    }

    public function test_latest_regrade_correction_is_effective_outcome(): void
    {
        $item = $this->item(
            'item-1',
            false,
            [
                ['skill-a', 'primary'],
            ],
        );

        $response = $item->response;

        $response->setRelation(
            'regradeCorrections',
            new Collection([
                $this->correction(
                    1,
                    false,
                ),
                $this->correction(
                    2,
                    true,
                ),
            ])
        );

        $attempt =
            $this->attempt([$item]);

        $evidence =
            (new ProjectAttemptSkillEvidence())
                ->execute($attempt);

        $this->assertTrue(
            $evidence[0][
                'effective_is_correct'
            ]
        );

        $this->assertSame(
            1,
            $evidence[0][
                'single_primary_correct_count'
            ]
        );
    }

    public function test_supporting_skill_gets_exposure_and_positive_on_correct_answer(): void
    {
        $attempt = $this->attempt([
            $this->item(
                'item-1',
                true,
                [
                    ['skill-a', 'primary'],
                    ['skill-b', 'supporting'],
                ],
            ),
        ]);

        $evidence =
            (new ProjectAttemptSkillEvidence())
                ->execute($attempt);

        $supporting = collect($evidence)
            ->firstWhere(
                'type',
                'supporting'
            );

        $this->assertSame(
            'skill-b',
            $supporting['skill_id']
        );

        $this->assertSame(
            1,
            $supporting[
                'supporting_exposure_count'
            ]
        );

        $this->assertSame(
            1,
            $supporting[
                'supporting_positive_count'
            ]
        );
    }

    public function test_supporting_incorrect_answer_has_exposure_but_no_negative_counter(): void
    {
        $attempt = $this->attempt([
            $this->item(
                'item-1',
                false,
                [
                    ['skill-a', 'primary'],
                    ['skill-b', 'supporting'],
                ],
            ),
        ]);

        $evidence =
            (new ProjectAttemptSkillEvidence())
                ->execute($attempt);

        $supporting = collect($evidence)
            ->firstWhere(
                'type',
                'supporting'
            );

        $this->assertSame(
            1,
            $supporting[
                'supporting_exposure_count'
            ]
        );

        $this->assertSame(
            0,
            $supporting[
                'supporting_positive_count'
            ]
        );

        $this->assertArrayNotHasKey(
            'supporting_incorrect_count',
            $supporting
        );
    }

    public function test_multi_primary_correct_answer_is_composite_only(): void
    {
        $attempt = $this->attempt([
            $this->item(
                'item-1',
                true,
                [
                    ['skill-b', 'primary'],
                    ['skill-a', 'primary'],
                ],
            ),
        ]);

        $evidence =
            (new ProjectAttemptSkillEvidence())
                ->execute($attempt);

        $this->assertCount(1, $evidence);

        $this->assertSame(
            'composite_primary',
            $evidence[0]['type']
        );

        $this->assertSame(
            ['skill-a', 'skill-b'],
            $evidence[0]['skill_ids']
        );

        $this->assertSame(
            'positive',
            $evidence[0]['outcome']
        );

        $this->assertArrayNotHasKey(
            'skill_id',
            $evidence[0]
        );

        $this->assertArrayNotHasKey(
            'single_primary_correct_count',
            $evidence[0]
        );
    }

    public function test_multi_primary_incorrect_answer_is_ambiguous_composite_failure(): void
    {
        $attempt = $this->attempt([
            $this->item(
                'item-1',
                false,
                [
                    ['skill-a', 'primary'],
                    ['skill-b', 'primary'],
                ],
            ),
        ]);

        $evidence =
            (new ProjectAttemptSkillEvidence())
                ->execute($attempt);

        $this->assertSame(
            'ambiguous_failure',
            $evidence[0]['outcome']
        );
    }

    public function test_unanswered_item_projects_no_skill_scoring_evidence(): void
    {
        $item = $this->item(
            'item-1',
            null,
            [
                ['skill-a', 'primary'],
                ['skill-b', 'supporting'],
            ],
        );

        $attempt =
            $this->attempt([$item]);

        $evidence =
            (new ProjectAttemptSkillEvidence())
                ->execute($attempt);

        $this->assertSame(
            [],
            $evidence
        );
    }

    public function test_in_progress_attempt_cannot_be_projected_as_historical_evidence(): void
    {
        $attempt =
            $this->attempt(
                [],
                'in_progress',
            );

        $this->expectException(
            LogicException::class
        );

        (new ProjectAttemptSkillEvidence())
            ->execute($attempt);
    }

    private function attempt(
        array $items,
        string $status = 'submitted',
    ): Attempt {
        $attempt = new Attempt();

        $attempt->id = 'attempt-1';
        $attempt->status = $status;

        $attempt->setRelation(
            'items',
            new Collection($items)
        );

        return $attempt;
    }

    private function item(
        string $id,
        ?bool $isCorrect,
        array $classifications,
    ): AttemptItem {
        $item = new AttemptItem();

        $item->id = $id;
        $item->assessment_item_id =
            'assessment-'.$id;
        $item->assessment_item_revision_id =
            'revision-'.$id;
        $item->primary_topic_id =
            'topic-'.$id;
        $item->presentation_position = 0;

        $models = [];

        foreach (
            $classifications
            as [$skillId, $role]
        ) {
            $classification =
                new AttemptItemClassificationSkill();

            $classification->skill_id =
                $skillId;
            $classification->role =
                $role;

            $models[] = $classification;
        }

        $item->setRelation(
            'classificationSkills',
            new Collection($models)
        );

        $response =
            new AttemptResponse();

        if ($isCorrect === null) {
            $response->response_payload =
                null;
            $response->original_is_correct =
                null;
        } else {
            $response->response_payload = [
                'selected_option' => 0,
            ];

            $response->original_is_correct =
                $isCorrect;
        }

        $response->setRelation(
            'regradeCorrections',
            new Collection()
        );

        $item->setRelation(
            'response',
            $response
        );

        return $item;
    }

    private function correction(
        int $number,
        bool $isCorrect,
    ): RegradeCorrection {
        $correction =
            new RegradeCorrection();

        $correction->correction_number =
            $number;
        $correction->corrected_is_correct =
            $isCorrect;

        return $correction;
    }
}
