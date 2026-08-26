<?php

namespace App\Application\Analytics;

use App\Application\Support\TransactionManager;
use App\Models\EvidenceScope;

class CreateEvidenceScope
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * The payload is intentionally opaque here.
     *
     * EvidenceScope semantic schema is not frozen yet.
     * This service establishes identity/lifecycle only.
     *
     * @param array<string, mixed> $definitionPayload
     */
    public function execute(
        ?string $label,
        ?string $description,
        array $definitionPayload,
        int $definitionSchemaVersion,
    ): EvidenceScope {
        return $this->transactions->run(
            fn (): EvidenceScope =>
                EvidenceScope::query()->create([
                    'label' => $label,
                    'description' => $description,
                    'definition_payload' =>
                        $definitionPayload,
                    'definition_schema_version' =>
                        $definitionSchemaVersion,
                    'status' => 'active',
                ])
        );
    }
}
