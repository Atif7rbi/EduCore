<?php

namespace App\Application\Analytics;

use App\Application\Support\TransactionManager;
use App\Models\EvidenceScope;

class RetireEvidenceScope
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function execute(
        string $evidenceScopeId,
    ): EvidenceScope {
        return $this->transactions->run(
            function () use (
                $evidenceScopeId,
            ): EvidenceScope {
                $scope = EvidenceScope::query()
                    ->whereKey($evidenceScopeId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($scope->status === 'retired') {
                    return $scope;
                }

                $scope->status = 'retired';
                $scope->save();

                return $scope->refresh();
            }
        );
    }
}
