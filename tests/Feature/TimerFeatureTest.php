<?php

namespace Tests\Feature;

use App\Events\TimerUpdated;
use App\Models\Role;
use App\Models\Timer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TimerFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_timer_role_and_user_are_seeded(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(UserSeeder::class);

        $timerRole = Role::where('name', 'Timer')->first();

        $this->assertNotNull($timerRole);
        $this->assertDatabaseHas('users', [
            'name' => 'Timer',
            'email' => 'timer@ema.id',
            'role_id' => $timerRole->id,
        ]);
    }

    public function test_timer_users_are_redirected_from_dashboard_to_timer_page(): void
    {
        $timerRole = Role::create(['name' => 'Timer']);
        $user = User::factory()->create(['role_id' => $timerRole->id]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('timer'));
    }

    public function test_timer_user_can_view_timer_page(): void
    {
        $timerRole = Role::create(['name' => 'Timer']);
        $user = User::factory()->create(['role_id' => $timerRole->id]);

        Timer::create([
            'is_display' => true,
            'is_countdown' => true,
            'second' => 90,
        ]);

        $this->actingAs($user)
            ->get(route('timer'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Timer')
                ->where('timer.is_display', true)
                ->where('timer.second', 90)
            );
    }

    public function test_non_timer_users_cannot_view_timer_page(): void
    {
        $operatorRole = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operatorRole->id]);

        $this->actingAs($user)
            ->get(route('timer'))
            ->assertForbidden();
    }

    public function test_timer_user_can_update_configuration_and_broadcast_it(): void
    {
        Event::fake([TimerUpdated::class]);

        $timerRole = Role::create(['name' => 'Timer']);
        $user = User::factory()->create(['role_id' => $timerRole->id]);

        $this->actingAs($user)
            ->postJson(route('timer.update'), [
                'is_display' => true,
                'is_countdown' => false,
                'is_autostop' => true,
                'second' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('timer.is_display', true)
            ->assertJsonPath('timer.is_countdown', false)
            ->assertJsonPath('timer.is_autostop', true)
            ->assertJsonPath('timer.second', 30);

        $this->assertDatabaseHas('timers', [
            'is_display' => true,
            'is_countdown' => false,
            'is_autostop' => true,
            'second' => 30,
        ]);

        Event::assertDispatched(TimerUpdated::class);
    }

    public function test_timer_user_can_start_pause_stop_and_reset_timer(): void
    {
        Event::fake([TimerUpdated::class]);

        $timerRole = Role::create(['name' => 'Timer']);
        $user = User::factory()->create(['role_id' => $timerRole->id]);
        Timer::create(['second' => 120]);

        $this->actingAs($user)
            ->postJson(route('timer.control'), ['action' => 'start'])
            ->assertOk()
            ->assertJsonPath('timer.status', 'running');

        $this->assertDatabaseHas('timers', ['status' => 'running']);

        $this->actingAs($user)
            ->postJson(route('timer.control'), ['action' => 'pause'])
            ->assertOk()
            ->assertJsonPath('timer.status', 'paused');

        $this->actingAs($user)
            ->postJson(route('timer.control'), ['action' => 'stop'])
            ->assertOk()
            ->assertJsonPath('timer.status', 'stopped');

        $this->actingAs($user)
            ->postJson(route('timer.control'), ['action' => 'reset'])
            ->assertOk()
            ->assertJsonPath('timer.elapsed_seconds', 0)
            ->assertJsonPath('timer.elapsed_milliseconds', 0);

        Event::assertDispatched(TimerUpdated::class, 4);
    }

    public function test_timer_pause_preserves_elapsed_milliseconds(): void
    {
        Event::fake([TimerUpdated::class]);

        $timerRole = Role::create(['name' => 'Timer']);
        $user = User::factory()->create(['role_id' => $timerRole->id]);

        Carbon::setTestNow(Carbon::parse('2026-04-26 10:00:00.111'));
        Timer::create(['second' => 120]);

        $this->actingAs($user)
            ->postJson(route('timer.control'), ['action' => 'start'])
            ->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-04-26 10:00:01.345'));

        $this->actingAs($user)
            ->postJson(route('timer.control'), ['action' => 'pause'])
            ->assertOk()
            ->assertJsonPath('timer.status', 'paused')
            ->assertJsonPath('timer.elapsed_seconds', 1)
            ->assertJsonPath('timer.elapsed_milliseconds', 1234);

        $this->assertDatabaseHas('timers', [
            'status' => 'paused',
            'elapsed_seconds' => 1,
            'elapsed_milliseconds' => 1234,
        ]);

        Carbon::setTestNow();
    }

    public function test_non_timer_users_cannot_update_timer(): void
    {
        $operatorRole = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operatorRole->id]);

        $this->actingAs($user)
            ->postJson(route('timer.update'), ['is_display' => true])
            ->assertForbidden();
    }
}
