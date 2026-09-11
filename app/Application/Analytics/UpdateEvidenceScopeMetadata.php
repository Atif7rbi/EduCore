<?php

namespace App\Application\Analytics;

use App\Application\Support\TransactionManager;
use App\Models\EvidenceScope;

class UpdateEvidenceScopeMetadata
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function execute(
        string $evidenceScopeId,
        ?string $label,
        ?string $description,
    ): EvidenceScope {
        return $this->transactions->run(
            function () use (
                $evidenceScopeId,
                $label,
                $description,
            ): EvidenceScope {
                $scope = EvidenceScope::query()
                    ->whereKey($evidenceScopeId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $scope->label = $label;
                $scope->description = $description;

                $scope->save();

                return $scope->refresh();
            }
        );
    }
}
