<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\TestCase;

class PostgresUtcConnectionContractTest extends TestCase
{
    use ResetsDedicatedTestDatabase;

    public function test_pgsql_connection_and_new_password_reset_token_use_utc(): void
    {
        DB::purge('pgsql');
        DB::reconnect('pgsql');

        $this->assertSame('UTC', date_default_timezone_get());
        $this->assertSame('UTC', config('app.timezone'));

        $timezone = (array) DB::selectOne('SHOW TIME ZONE');

        $this->assertSame('UTC', array_values($timezone)[0]);

        $user = User::factory()->create([
            'email' => 'utc-contract@example.com',
            'role' => 'admin',
        ]);

        $issuedAt = Carbon::now('UTC');
        $token = Password::broker()->createToken($user);
        $record = DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->first();

        $this->assertNotNull($record);
        $this->assertTrue(Hash::check($token, $record->token));
        $this->assertTrue(
            Password::broker()->tokenExists($user, $token),
        );
        $this->assertLessThanOrEqual(
            1,
            abs(
                Carbon::parse($record->created_at)
                    ->getTimestamp() - $issuedAt->getTimestamp(),
            ),
        );
    }
}
