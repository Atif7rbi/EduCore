<?php

namespace App\Application\Curriculum;

use App\Application\Exceptions\CurriculumVersionNotReady;
use App\Application\Support\TransactionManager;
use App\Models\CurriculumVersion;

class PublishCurriculumVersion
{
    private readonly EvaluateCurriculumVersionReadiness $readiness;

    public function __construct(
        private readonly TransactionManager $transactions,
        ?EvaluateCurriculumVersionReadiness $readiness = null,
    ) {
        $this->readiness =
            $readiness
            ?? new EvaluateCurriculumVersionReadiness();
    }

    public function execute(string $curriculumVersionId): CurriculumVersion
    {
        return $this->transactions->run(
            function () use ($curriculumVersionId): CurriculumVersion {
                $version = CurriculumVersion::query()
                    ->whereKey($curriculumVersionId)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * Preserve the existing idempotent published call.
                 */
                if ($version->status === 'published') {
                    return $version->refresh();
                }

                /*
                 * Preserve PostgreSQL as the lifecycle authority for
                 * invalid terminal transitions such as retired → published.
                 */
                if ($version->status !== 'draft') {
                    $version->status = 'published';
                    $version->save();

                    return $version->refresh();
                }

                /*
                 * The CurriculumVersion parent lock is held while
                 * readiness is evaluated. All readiness-relevant
                 * authoring mutations must serialize on this same
                 * parent row.
                 */
                $readiness =
                    $this->readiness->evaluate($version);

                if (! $readiness['ready_to_publish']) {
                    throw new CurriculumVersionNotReady(
                        $readiness['blockers']
                    );
                }

                $version->status = 'published';
                $version->save();

                return $version->refresh();
            }
        );
    }
}
