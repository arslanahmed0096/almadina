<?php

namespace Tests\Unit;

use App\Models\UserLoginSession;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserLoginSessionRetentionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-07 14:00:00'));
        Schema::create('user_login_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id');
            $table->string('access_token_id', 100)->unique();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('logged_in_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('user_login_sessions');
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_only_the_last_48_hours_of_activity_history_are_kept(): void
    {
        $this->insertSession('recent-token', now()->subHours(47));
        $this->insertSession('old-token', now()->subHours(49));
        $this->insertSession('cookie:old-active', now()->subHours(49));
        $this->insertSession('cookie:revoked-tombstone', now()->subHours(49), now()->subHours(48));

        $deleted = UserLoginSession::purgeExpiredHistory();

        $this->assertSame(2, $deleted);
        $this->assertDatabaseHas('user_login_sessions', ['access_token_id' => 'recent-token']);
        $this->assertDatabaseMissing('user_login_sessions', ['access_token_id' => 'old-token']);
        $this->assertDatabaseMissing('user_login_sessions', ['access_token_id' => 'cookie:old-active']);

        // Kept internally to prevent a revoked Passport cookie from being revived,
        // but excluded from every 48-hour history query.
        $this->assertDatabaseHas('user_login_sessions', ['access_token_id' => 'cookie:revoked-tombstone']);
        $visibleIds = UserLoginSession::query()
            ->withinHistoryRetention()
            ->pluck('access_token_id')
            ->all();
        $this->assertSame(['recent-token'], $visibleIds);
    }

    private function insertSession(string $tokenId, Carbon $lastActivity, ?Carbon $revokedAt = null): void
    {
        DB::table('user_login_sessions')->insert([
            'user_id' => 1,
            'access_token_id' => $tokenId,
            'logged_in_at' => $lastActivity->copy()->subMinute(),
            'last_activity_at' => $lastActivity,
            'revoked_at' => $revokedAt,
            'created_at' => $lastActivity->copy()->subMinute(),
            'updated_at' => $lastActivity,
        ]);
    }
}
