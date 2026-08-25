<?php

namespace App\Application\Curriculum;

use App\Application\Support\TransactionManager;
use App\Models\CurriculumVersion;

class PublishCurriculumVersion
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function execute(string $curriculumVersionId): CurriculumVersion
    {
        return $this->transactions->run(
            function () use ($curriculumVersionId): CurriculumVersion {
                $version = CurriculumVersion::query()
                    ->whereKey($curriculumVersionId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $version->status = 'published';
                $version->save();

                return $version->refresh();
            }
        );
    }
}
