<?php

namespace App\Application\Analytics;

use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\AttemptResponse;
use LogicException;

class ProjectAttemptSkillEvidence
{
    /**
     * Project immutable historical attempt truth into
     * non-materialized measurement statements.
     *
     * This intentionally does NOT:
     * - apply EvidenceScope eligibility;
     * - apply repetition policy;
     * - calculate mastery;
     * - calculate sufficiency;
     * - calculate any global score.
     *
     * @return array<int, array<string, mixed>>
     */
    public function execute(
        Attempt $attempt,
    ): array {
        if (
            ! in_array(
                $attempt->status,
                ['submitted', 'abandoned'],
                true,
            )
        ) {
            throw new LogicException(
                'Historical skill evidence requires a finalized attempt.'
            );
        }

        if (! $this->evidenceRelationsAreLoaded($attempt)) {
            $attempt->loadMissing([
                'items.classificationSkills',
                'items.response.regradeCorrections',
            ]);
        }

        $statements = [];

        foreach (
            $attempt->items
                ->sortBy('presentation_position')
                ->values()
            as $item
        ) {
            foreach (
                $this->projectItem($item)
                as $statement
            ) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    private function evidenceRelationsAreLoaded(
        Attempt $attempt,
    ): bool {
        if (! $attempt->relationLoaded('items')) {
            return false;
        }

        foreach ($attempt->items as $item) {
            if (
                ! $item->relationLoaded(
                    'classificationSkills'
                )
                || ! $item->relationLoaded(
                    'response'
                )
            ) {
                return false;
            }

            $response = $item->response;

            if (
                $response !== null
                && ! $response->relationLoaded(
                    'regradeCorrections'
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function projectItem(
        AttemptItem $item,
    ): array {
        $response = $item->response;

        if (
            ! $response instanceof AttemptResponse
            || $response->response_payload === null
            || $response->original_is_correct === null
        ) {
            return [];
        }

        $effectiveIsCorrect =
            $this->effectiveIsCorrect(
                $response
            );

        $primarySkillIds =
            $item->classificationSkills
                ->where('role', 'primary')
                ->pluck('skill_id')
                ->unique()
                ->sort()
                ->values()
                ->all();

        $supportingSkillIds =
            $item->classificationSkills
                ->where('role', 'supporting')
                ->pluck('skill_id')
                ->unique()
                ->sort()
                ->values()
                ->all();

        if (count($primarySkillIds) < 1) {
            throw new LogicException(
                'Historical AttemptItem requires at least one primary Skill.'
            );
        }

        $base = [
            'attempt_item_id' => $item->id,
            'assessment_item_id' =>
                $item->assessment_item_id,
            'assessment_item_revision_id' =>
                $item->assessment_item_revision_id,
            'primary_topic_id' =>
                $item->primary_topic_id,
            'effective_is_correct' =>
                $effectiveIsCorrect,
        ];

        $statements = [];

        if (count($primarySkillIds) === 1) {
            $statements[] = [
                ...$base,
                'type' => 'single_primary',
                'skill_id' =>
                    $primarySkillIds[0],
                'single_primary_answered_count' =>
                    1,
                'single_primary_correct_count' =>
                    $effectiveIsCorrect
                        ? 1
                        : 0,
            ];
        } else {
            $statements[] = [
                ...$base,
                'type' => 'composite_primary',
                'skill_ids' =>
                    $primarySkillIds,
                'outcome' =>
                    $effectiveIsCorrect
                        ? 'positive'
                        : 'ambiguous_failure',
            ];
        }

        foreach (
            $supportingSkillIds
            as $supportingSkillId
        ) {
            $statements[] = [
                ...$base,
                'type' => 'supporting',
                'skill_id' =>
                    $supportingSkillId,
                'supporting_exposure_count' =>
                    1,
                'supporting_positive_count' =>
                    $effectiveIsCorrect
                        ? 1
                        : 0,
            ];
        }

        return $statements;
    }

    private function effectiveIsCorrect(
        AttemptResponse $response,
    ): bool {
        $latestCorrection =
            $response->regradeCorrections
                ->sortByDesc(
                    'correction_number'
                )
                ->first();

        if ($latestCorrection !== null) {
            return (bool)
                $latestCorrection
                    ->corrected_is_correct;
        }

        return (bool)
            $response->original_is_correct;
    }
}
