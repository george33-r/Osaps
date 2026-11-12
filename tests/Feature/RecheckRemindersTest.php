<?php

namespace Tests\Feature;

use App\Models\TailorProfile;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Re-check reminders: once per cycle, only inside the warning window or once overdue. */
class RecheckRemindersTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedTailor(\DateTimeInterface|string $due): TailorProfile
    {
        $user = User::factory()->tailor()->create();

        return TailorProfile::factory()->create(['user_id' => $user->id, 'verification_status' => 'verified', 'recheck_due_at' => $due]);
    }

    public function test_one_reminder_is_sent_inside_the_warning_window_and_not_repeated(): void
    {
        $profile = $this->verifiedTailor(now()->addDays(10));

        $this->artisan('verification:recheck-reminders')->assertSuccessful();
        $this->artisan('verification:recheck-reminders')->assertSuccessful();

        $this->assertSame(1, UserNotification::query()->where('user_id', $profile->user_id)->where('kind', 'verification_recheck')->count());
        $this->assertNotNull($profile->fresh()->recheck_reminded_at);
    }

    public function test_nothing_is_sent_before_the_warning_window(): void
    {
        $profile = $this->verifiedTailor(now()->addDays(30));

        $this->artisan('verification:recheck-reminders')->assertSuccessful();

        $this->assertSame(0, UserNotification::query()->where('user_id', $profile->user_id)->count());
    }

    public function test_an_overdue_check_gets_a_reminder_and_a_new_cycle_gets_another(): void
    {
        $profile = $this->verifiedTailor(now()->subDay());

        $this->artisan('verification:recheck-reminders')->assertSuccessful();
        $this->assertSame(1, UserNotification::query()->where('user_id', $profile->user_id)->count());

        // A completed check restarts the clock; the next warning window gets its own reminder.
        // The previous reminder belongs to the old cycle; the new cycle's window needs its own.
        $profile->forceFill(['recheck_due_at' => now()->addDays(10), 'recheck_reminded_at' => now()->subWeeks(7)])->save();
        $this->artisan('verification:recheck-reminders')->assertSuccessful();
        $this->assertSame(2, UserNotification::query()->where('user_id', $profile->user_id)->count());
    }
}
