<?php

namespace Tests\Feature\Concurrency;

use App\Application\Exceptions\IntegrityConstraintViolation;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\TestCase;

class PhaseH5WorkerProtocolTest extends TestCase
{
    use ResetsDedicatedTestDatabase;

    public function test_unexpected_worker_exception_is_structured_and_nonzero(): void
    {
        $r = $this->launch('h5_test_unexpected_exception');
        $this->assertNotSame(0, $r['exit']);
        $this->assertFalse($r['json']['ok']);
        $this->assertSame('unexpected', $r['json']['failure_type']);
        $this->assertSame('RuntimeException', $r['json']['exception_class']);
        $this->assertNull($r['json']['sqlstate']);
    }

    public function test_worker_database_failure_propagates_sqlstate_and_nonzero_exit(): void
    {
        $r = $this->launch('h5_test_sqlstate_violation');
        $this->assertNotSame(0, $r['exit']);
        $this->assertFalse($r['json']['ok']);
        $this->assertSame('unexpected', $r['json']['failure_type']);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{5}$/', $r['json']['sqlstate']);
    }

    public function test_translated_integrity_failure_is_structured_nonzero_and_preserves_sqlstate(): void
    {
        $r = $this->launch('h5_test_translated_integrity_violation');
        $this->assertNotSame(0, $r['exit']);
        $this->assertFalse($r['json']['ok']);
        $this->assertSame('unexpected', $r['json']['failure_type']);
        $this->assertSame(IntegrityConstraintViolation::class, $r['json']['exception_class']);
        $this->assertSame('23505', $r['json']['sqlstate']);
    }

    private function launch(string $action): array
    {
        $d = sys_get_temp_dir();
        $ready = tempnam($d, 'h5r');
        $go = tempnam($d, 'h5g');
        $result = tempnam($d, 'h5o');
        unlink($ready);
        file_put_contents($go, 'GO');
        $payload = base64_encode(json_encode(['action' => $action, 'ready_file' => $ready, 'go_file' => $go, 'result_file' => $result]));
        $p = proc_open([PHP_BINARY, base_path('tests/Support/phase_h5_concurrency_worker.php'), $payload], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($p);
        $json = json_decode((string) file_get_contents($result), true, 512, JSON_THROW_ON_ERROR);
        foreach ([$ready, $go, $result] as $f) {
            @unlink($f);
        }

        return ['exit' => $exit, 'json' => $json, 'stderr' => $err];
    }
}
