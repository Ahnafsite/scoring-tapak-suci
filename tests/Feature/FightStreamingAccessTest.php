<?php

namespace Tests\Feature;

use App\Models\Arena;
use App\Models\FightMatch;
use App\Models\FightRecapJuryPoint;
use App\Models\Role;
use App\Models\Timer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FightStreamingAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $response = $this->get(route('fight-streaming'));

        $response->assertRedirect(route('login'));

        $response = $this->get(route('fight-streaming-online'));

        $response->assertRedirect(route('login'));
    }

    public function test_streamer_can_view_fight_streaming_page(): void
    {
        $streamer = Role::create(['name' => 'Streamer']);
        $user = User::factory()->create(['role_id' => $streamer->id]);

        Arena::create([
            'arena_name' => 'A',
            'gelanggang_id' => 1,
            'sesi_tanding_id' => 1,
        ]);

        FightMatch::create([
            'match_code' => '001',
            'group' => 'Putra',
            'category' => 'Dewasa',
            'status' => 'ongoing',
            'round_number' => 1,
            'atlete_yellow' => 'Atlet Kuning',
            'atlete_blue' => 'Atlet Biru',
            'contingent_yellow' => 'Kontingen A',
            'contingent_blue' => 'Kontingen B',
        ]);

        FightRecapJuryPoint::create([
            'round_number' => 1,
            'total_poin_yellow' => 40,
            'total_poin_blue' => 20,
            'jury_one_total_poin_yellow' => 40,
            'jury_one_total_poin_blue' => 20,
            'jury_one_winner' => 'yellow',
        ]);

        Timer::create([
            'is_display' => true,
            'second' => 90,
        ]);

        $response = $this->actingAs($user)->get(route('fight-streaming'));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('FightStreaming')
                ->where('activeMatch.match_code', '001')
                ->where('activeMatch.status', 'ongoing')
                ->where('recapPoints.0.total_poin_yellow', 40)
                ->where('timer.is_display', true)
                ->where('timer.second', 90)
                ->has('yellowPoints')
                ->has('bluePoints')
            );
    }

    public function test_streamer_can_view_fight_streaming_online_page(): void
    {
        $streamer = Role::create(['name' => 'Streamer']);
        $user = User::factory()->create(['role_id' => $streamer->id]);

        Arena::create([
            'arena_name' => 'A',
            'gelanggang_id' => 1,
            'sesi_tanding_id' => 1,
        ]);

        FightMatch::create([
            'match_code' => '002',
            'group' => 'Putri',
            'category' => 'Remaja',
            'status' => 'not_started',
            'round_number' => 1,
            'atlete_yellow' => 'Atlet Kuning Online',
            'atlete_blue' => 'Atlet Biru Online',
            'contingent_yellow' => 'Kontingen C',
            'contingent_blue' => 'Kontingen D',
        ]);

        FightRecapJuryPoint::create([
            'round_number' => 1,
            'total_poin_yellow' => 30,
            'total_poin_blue' => 50,
            'jury_one_total_poin_yellow' => 30,
            'jury_one_total_poin_blue' => 50,
            'jury_one_winner' => 'blue',
        ]);

        Timer::create([
            'is_display' => true,
            'second' => 90,
        ]);

        $response = $this->actingAs($user)->get(route('fight-streaming-online'));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('FightStreamingOnline')
                ->where('activeMatch.match_code', '002')
                ->where('activeMatch.status', 'not_started')
                ->where('recapPoints.0.total_poin_blue', 50)
                ->where('timer.is_display', true)
                ->has('yellowPoints')
                ->has('bluePoints')
            );
    }

    public function test_non_streamers_cannot_view_fight_streaming_page(): void
    {
        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);

        $response = $this->actingAs($user)->get(route('fight-streaming'));

        $response->assertForbidden();

        $response = $this->actingAs($user)->get(route('fight-streaming-online'));

        $response->assertForbidden();
    }
}
