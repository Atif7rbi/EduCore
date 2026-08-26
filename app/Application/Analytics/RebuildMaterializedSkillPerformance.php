<?php

namespace App\Application\Analytics;

use App\Application\Support\TransactionManager;
use App\Models\Attempt;
use App\Models\EvidenceScope;
use App\Models\LearnerProfile;
use App\Models\MaterializedSkillPerformance;
use App\Models\Skill;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RebuildMaterializedSkillPerformance
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly ProjectAttemptSkillEvidence $projector,
    ) {
    }

    /**
     * Rebuild exactly one learner × skill × scope cache key.
     *
     * Eligibility is intentionally external to this service.
     * The supplied Attempt IDs are the already-resolved evidence set.
     *
     * @param array<int, string> $eligibleAttemptIds
     */
    public function execute(
        string $learnerProfileId,
        string $skillId,
        string $evidenceScopeId,
        array $eligibleAttemptIds,
    ): ?MaterializedSkillPerformance {
        $attemptIds = collect(
            $eligibleAttemptIds
        )
            ->map(
                fn (mixed $id): string =>
                    (string) $id
            )
            ->unique()
            ->values();

        if (
            $attemptIds->contains(
                fn (string $id): bool =>
                    ! Str::isUuid($id)
            )
        ) {
            throw new InvalidArgumentException(
                'Eligible Attempt IDs must be valid UUIDs.'
            );
        }

        return $this->transactions->run(
            function () use (
                $learnerProfileId,
                $skillId,
                $evidenceScopeId,
                $attemptIds,
            ): ?MaterializedSkillPerformance {
                LearnerProfile::query()
                    ->whereKey($learnerProfileId)
                    ->firstOrFail();

                Skill::query()
                    ->whereKey($skillId)
                    ->firstOrFail();

                EvidenceScope::query()
                    ->whereKey($evidenceScopeId)
                    ->firstOrFail();

                /*
                 * Serialize the full rebuild key, including the
                 * zero-evidence delete path.
                 *
                 * This mirrors the DB trigger's advisory-lock key.
                 */
                DB::select(
                    <<<'SQL'
SELECT pg_advisory_xact_lock(
    hashtext(?),
    hashtext(?)
)
SQL,
                    [
                        $learnerProfileId,
                        $skillId
                            .':'
                            .$evidenceScopeId,
                    ],
                );

                /** @var Collection<int, Attempt> $attempts */
                $attempts = Attempt::query()
                    ->whereIn(
                        'id',
                        $attemptIds->all()
                    )
                    ->where(
                        'learner_profile_id',
                        $learnerProfileId,
                    )
                    ->whereIn(
                        'status',
                        [
                            'submitted',
                            'abandoned',
                        ],
                    )
                    ->with([
                        'items.classificationSkills',
                        'items.response.regradeCorrections',
                    ])
                    ->orderBy('id')
                    ->get();

                if (
                    $attempts->count()
                    !== $attemptIds->count()
                ) {
                    throw new InvalidArgumentException(
                        'Every eligible Attempt must exist, belong to the learner, and be finalized.'
                    );
                }

                $counts = [
                    'single_primary_correct_count' =>
                        0,
                    'single_primary_answered_count' =>
                        0,
                    'supporting_positive_count' =>
                        0,
                    'supporting_exposure_count' =>
                        0,
                ];

                foreach ($attempts as $attempt) {
                    foreach (
                        $this->projector->execute(
                            $attempt
                        )
                        as $statement
                    ) {
                        if (
                            ($statement['type'] ?? null)
                            === 'single_primary'
                            && (
                                $statement[
                                    'skill_id'
                                ] ?? null
                            ) === $skillId
                        ) {
                            $counts[
                                'single_primary_correct_count'
                            ] += (int) $statement[
                                'single_primary_correct_count'
                            ];

                            $counts[
                                'single_primary_answered_count'
                            ] += (int) $statement[
                                'single_primary_answered_count'
                            ];
                        }

                        if (
                            ($statement['type'] ?? null)
                            === 'supporting'
                            && (
                                $statement[
                                    'skill_id'
                                ] ?? null
                            ) === $skillId
                        ) {
                            $counts[
                                'supporting_positive_count'
                            ] += (int) $statement[
                                'supporting_positive_count'
                            ];

                            $counts[
                                'supporting_exposure_count'
                            ] += (int) $statement[
                                'supporting_exposure_count'
                            ];
                        }

                        /*
                         * composite_primary is intentionally not
                         * distributed into individual Skill counters.
                         */
                    }
                }

                $hasEvidence =
                    array_sum($counts) > 0;

                if (! $hasEvidence) {
                    MaterializedSkillPerformance::query()
                        ->where(
                            'learner_profile_id',
                            $learnerProfileId,
                        )
                        ->where(
                            'skill_id',
                            $skillId,
                        )
                        ->where(
                            'evidence_scope_id',
                            $evidenceScopeId,
                        )
                        ->delete();

                    return null;
                }

                $now =
                    CarbonImmutable::now('UTC');

                MaterializedSkillPerformance::query()
                    ->updateOrCreate(
                        [
                            'learner_profile_id' =>
                                $learnerProfileId,
                            'skill_id' =>
                                $skillId,
                            'evidence_scope_id' =>
                                $evidenceScopeId,
                        ],
                        [
                            ...$counts,
                            'last_rebuilt_at' =>
                                $now,
                        ],
                    );

                return MaterializedSkillPerformance::query()
                    ->where(
                        'learner_profile_id',
                        $learnerProfileId,
                    )
                    ->where(
                        'skill_id',
                        $skillId,
                    )
                    ->where(
                        'evidence_scope_id',
                        $evidenceScopeId,
                    )
                    ->firstOrFail();
            }
        );
    }
}
