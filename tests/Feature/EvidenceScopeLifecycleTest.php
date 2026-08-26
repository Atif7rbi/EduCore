<?php

namespace Tests\Feature;

use App\Application\Analytics\CreateEvidenceScope;
use App\Application\Analytics\RetireEvidenceScope;
use App\Application\Analytics\UpdateEvidenceScopeMetadata;
use App\Models\EvidenceScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EvidenceScopeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_is_created_active_with_opaque_definition(): void
    {
        $scope = app(
            CreateEvidenceScope::class
        )->execute(
            'Baseline scope',
            'Opaque analytical definition',
            [
                'purpose' =>
                    'test-contract-only',
                'configuration' => [
                    'example' => true,
                ],
            ],
            1,
        );

        $this->assertSame(
            'active',
            $scope->status
        );

        $this->assertSame(
            [
                'purpose' =>
                    'test-contract-only',
                'configuration' => [
                    'example' => true,
                ],
            ],
            $scope->definition_payload
        );

        $this->assertSame(
            1,
            $scope->definition_schema_version
        );
    }

    public function test_scope_metadata_can_change_without_changing_identity_definition(): void
    {
        $scope = $this->scope();

        $updated = app(
            UpdateEvidenceScopeMetadata::class
        )->execute(
            $scope->id,
            'Renamed scope',
            'Updated description',
        );

        $this->assertSame(
            'Renamed scope',
            $updated->label
        );

        $this->assertSame(
            'Updated description',
            $updated->description
        );

        $this->assertSame(
            $scope->definition_payload,
            $updated->definition_payload
        );

        $this->assertSame(
            $scope->definition_schema_version,
            $updated->definition_schema_version
        );
    }

    public function test_semantic_definition_is_database_immutable(): void
    {
        $scope = $this->scope();

        $this->expectException(
            QueryException::class
        );

        DB::table('evidence_scopes')
            ->where('id', $scope->id)
            ->update([
                'definition_payload' =>
                    json_encode(
                        [
                            'changed' => true,
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                'updated_at' => now(),
            ]);
    }

    public function test_definition_schema_version_is_database_immutable(): void
    {
        $scope = $this->scope();

        $this->expectException(
            QueryException::class
        );

        DB::table('evidence_scopes')
            ->where('id', $scope->id)
            ->update([
                'definition_schema_version' => 2,
                'updated_at' => now(),
            ]);
    }

    public function test_scope_can_be_retired(): void
    {
        $scope = $this->scope();

        $retired = app(
            RetireEvidenceScope::class
        )->execute(
            $scope->id
        );

        $this->assertSame(
            'retired',
            $retired->status
        );
    }

    public function test_retirement_is_idempotent_at_application_boundary(): void
    {
        $scope = $this->scope();

        $service = app(
            RetireEvidenceScope::class
        );

        $first = $service->execute(
            $scope->id
        );

        $second = $service->execute(
            $scope->id
        );

        $this->assertSame(
            'retired',
            $first->status
        );

        $this->assertSame(
            'retired',
            $second->status
        );
    }

    public function test_retired_scope_cannot_be_reactivated_at_database_boundary(): void
    {
        $scope = $this->scope();

        app(
            RetireEvidenceScope::class
        )->execute(
            $scope->id
        );

        $this->expectException(
            QueryException::class
        );

        DB::table('evidence_scopes')
            ->where('id', $scope->id)
            ->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);
    }

    public function test_scope_cannot_be_created_retired_even_by_direct_database_write(): void
    {
        $this->expectException(
            QueryException::class
        );

        DB::table('evidence_scopes')->insert([
            'id' =>
                (string) \Illuminate\Support\Str::uuid(),
            'label' => 'Invalid',
            'description' => null,
            'definition_payload' =>
                json_encode(
                    ['opaque' => true],
                    JSON_THROW_ON_ERROR,
                ),
            'definition_schema_version' => 1,
            'status' => 'retired',
            'created_at' => now(),
            'updated_at' => null,
        ]);
    }

    private function scope(): EvidenceScope
    {
        return app(
            CreateEvidenceScope::class
        )->execute(
            'Scope '.uniqid(),
            null,
            [
                'opaque' => [
                    'value' => true,
                ],
            ],
            1,
        );
    }
}
