<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ParentLockReadyArtifact;

final class ParentLockReadyArtifactTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir()
            .'/educore-ready-test-'
            .bin2hex(random_bytes(8));

        if (! mkdir($this->directory, 0700)) {
            throw new RuntimeException(
                'Unable to create READY test directory.',
            );
        }
    }

    protected function tearDown(): void
    {
        foreach (
            glob($this->directory.'/*') ?: [] as $path
        ) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_atomic_publication_round_trips_a_complete_payload(): void
    {
        $path = $this->path();
        $payload = $this->payload();

        ParentLockReadyArtifact::publish(
            $path,
            $payload,
        );

        $this->assertSame(
            $payload,
            ParentLockReadyArtifact::read($path),
        );
        $this->assertSame(
            [$path],
            glob($this->directory.'/*'),
        );
    }

    public function test_partial_json_is_not_parsed_before_complete_ready_arrives(): void
    {
        $path = $this->path();
        file_put_contents($path, '{');
        $checks = 0;

        $ready = ParentLockReadyArtifact::waitForValid(
            $path,
            function () use (
                &$checks,
                $path,
            ): bool {
                $checks++;

                if ($checks === 1) {
                    file_put_contents(
                        $path,
                        json_encode(
                            $this->payload(),
                            JSON_THROW_ON_ERROR,
                        ),
                    );
                }

                return true;
            },
            0.1,
            0,
        );

        $this->assertSame($this->payload(), $ready);
        $this->assertSame(1, $checks);
    }

    public function test_permanently_malformed_ready_fails_with_useful_diagnostics(): void
    {
        $path = $this->path();
        file_put_contents($path, '{');

        try {
            ParentLockReadyArtifact::waitForValid(
                $path,
                static fn (): bool => true,
                0.002,
                100,
            );
            $this->fail(
                'Malformed READY artifact unexpectedly passed.',
            );
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'Timed out waiting',
                $exception->getMessage(),
            );
            $this->assertStringContainsString(
                'incomplete or malformed',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString(
                '{',
                $exception->getMessage(),
            );
        }
    }

    public function test_child_exit_before_valid_ready_remains_detectable(): void
    {
        $path = $this->path();
        file_put_contents($path, '{');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Child exited before a valid READY artifact',
        );

        ParentLockReadyArtifact::waitForValid(
            $path,
            static fn (): bool => false,
            0.1,
            0,
        );
    }

    public function test_timeout_without_ready_remains_detectable(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Timed out waiting for a valid READY artifact',
        );

        ParentLockReadyArtifact::waitForValid(
            $this->path(),
            static fn (): bool => true,
            0.002,
            100,
        );
    }

    public function test_existing_ready_is_not_overwritten(): void
    {
        $path = $this->path();
        file_put_contents($path, 'existing');

        try {
            ParentLockReadyArtifact::publish(
                $path,
                $this->payload(),
            );
            $this->fail(
                'Existing READY artifact was overwritten.',
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'READY artifact already exists.',
                $exception->getMessage(),
            );
            $this->assertSame(
                'existing',
                file_get_contents($path),
            );
            $this->assertSame(
                [$path],
                glob($this->directory.'/*'),
            );
        }
    }

    private function path(): string
    {
        return $this->directory.'/ready.json';
    }

    /**
     * @return array{pid: int, database: string, user: string}
     */
    private function payload(): array
    {
        return [
            'pid' => 4242,
            'database' => 'sewaellf_educore_test',
            'user' => 'sewaellf_educore_Admin',
        ];
    }
}
