<?php

namespace Tests\Feature;

use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class TransactionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->whereIn('id', [
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
        ])->delete();
    }

    public function test_successful_transaction_commits_and_returns_result(): void
    {
        $manager = new TransactionManager(
            new PostgresExceptionTranslator()
        );

        $result = $manager->run(function (): string {
            DB::table('users')->insert([
                'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'name' => 'Transaction Test',
                'email' => 'transaction-success@example.test',
                'password' => 'not-used',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return 'committed';
        });

        $this->assertSame('committed', $result);

        $this->assertDatabaseHas('users', [
            'email' => 'transaction-success@example.test',
        ]);
    }

    public function test_runtime_failure_rolls_back_transaction(): void
    {
        $manager = new TransactionManager(
            new PostgresExceptionTranslator()
        );

        try {
            $manager->run(function (): void {
                DB::table('users')->insert([
                    'id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                    'name' => 'Rollback Test',
                    'email' => 'transaction-rollback@example.test',
                    'password' => 'not-used',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                throw new RuntimeException('force rollback');
            });

            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertDatabaseMissing('users', [
            'email' => 'transaction-rollback@example.test',
        ]);
    }

    public function test_query_failure_rolls_back_and_translates_integrity_error(): void
    {
        $manager = new TransactionManager(
            new PostgresExceptionTranslator()
        );

        DB::table('users')->insert([
            'id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'name' => 'Existing User',
            'email' => 'transaction-duplicate@example.test',
            'password' => 'not-used',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $manager->run(function (): void {
                DB::table('users')->insert([
                    'id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
                    'name' => 'Temporary User',
                    'email' => 'transaction-temp@example.test',
                    'password' => 'not-used',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('users')->insert([
                    'id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
                    'name' => 'Duplicate User',
                    'email' => 'transaction-duplicate@example.test',
                    'password' => 'not-used',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('23505', $exception->sqlState);
        }

        $this->assertDatabaseMissing('users', [
            'email' => 'transaction-temp@example.test',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'transaction-duplicate@example.test',
        ]);
    }
}
