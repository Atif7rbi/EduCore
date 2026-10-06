<?php

namespace App\Application\Curriculum;

use App\Application\Support\TransactionManager;
use App\Models\CurriculumVersion;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class RetireCurriculumVersion
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {}

    public function execute(
        string $curriculumVersionId,
        ?Closure $lockAuthority = null,
    ): CurriculumVersion {
        return $this->transactions->run(
            function () use (
                $curriculumVersionId,
                $lockAuthority,
            ): CurriculumVersion {
                $authorizedCurriculumId = $lockAuthority?->__invoke();

                $version = CurriculumVersion::query()
                    ->whereKey($curriculumVersionId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $authorizedCurriculumId !== null
                    && $version->curriculum_id
                        !== $authorizedCurriculumId
                ) {
                    throw (new ModelNotFoundException)->setModel(
                        CurriculumVersion::class,
                        [$curriculumVersionId],
                    );
                }

                $version->status = 'retired';
                $version->save();

                return $version->refresh();
            }
        );
    }
}
