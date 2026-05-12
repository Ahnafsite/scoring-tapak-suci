<?php

namespace Tests\Feature;

use App\Models\Arena;
use App\Models\Role;
use App\Models\SeniSingleMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SeniStreamingAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('seni-streaming'))
            ->assertRedirect(route('login'));

        $this->get(route('seni-streaming-online'))
            ->assertRedirect(route('login'));
    }

    public function test_streamer_can_view_seni_streaming_page(): void
    {
        $streamer = Role::create(['name' => 'Streamer']);
        $user = User::factory()->create(['role_id' => $streamer->id]);

        Arena::create(['arena_name' => 'Gelanggang A']);

        $match = SeniSingleMatch::create([
            'no_pool_babak_id' => 55,
            'bkp_id' => 3410,
            'matches_code' => 'S-01',
            'atletes' => 'Atlet A',
            'contingent' => 'Kontingen A',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putra',
            'status' => 'ongoing',
            'is_active' => true,
            'round_match' => 'Final',
            'no_order' => 1,
            'total_score' => 378,
            'total_wiraga' => 56,
            'total_wirama' => 54,
            'total_punishment' => 2,
            'time' => 140,
        ]);

        $match->juryScores()->create([
            'jury_number' => 1,
            'wiraga' => 70,
            'wirasa' => 60,
            'wirama' => 50,
            'total_score' => 180,
            'is_accepted' => true,
        ]);

        $match->juryPunishments()->create([
            'jury_number' => 1,
            'waktu' => 0,
            'keluar_garis' => 2,
            'senjata_jatuh_atau_tidak_sesuai_deskripsi' => 0,
            'akeseoris_jatuh' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('seni-streaming'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SeniStreaming')
                ->has('arena')
                ->where('activeMatch.id', $match->id)
                ->where('activeMatch.total_score', '378.000')
                ->where('activeMatch.total_wiraga', '56.000')
                ->where('activeMatch.total_wirama', '54.000')
                ->where('activeMatch.total_punishment', '2.000')
                ->where('activeMatch.time', 140)
                ->has('activeMatch.jury_scores', 1)
                ->where('activeMatch.jury_scores.0.total_score', '180.000')
                ->where('activeMatch.jury_scores.0.is_accepted', true)
                ->has('activeMatch.jury_punishments', 1)
                ->where('activeMatch.jury_punishments.0.keluar_garis', '2.000')
            );
    }

    public function test_streamer_can_view_seni_streaming_online_page(): void
    {
        $streamer = Role::create(['name' => 'Streamer']);
        $user = User::factory()->create(['role_id' => $streamer->id]);

        Arena::create(['arena_name' => 'Gelanggang B']);

        $match = SeniSingleMatch::create([
            'no_pool_babak_id' => 55,
            'bkp_id' => 3411,
            'matches_code' => 'S-02',
            'atletes' => 'Atlet Online',
            'contingent' => 'Kontingen Online',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putri',
            'status' => 'ongoing',
            'is_active' => true,
            'round_match' => 'Final',
            'no_order' => 2,
            'total_score' => 360,
            'total_wiraga' => 55,
            'total_wirasa' => 53,
            'total_wirama' => 52,
            'total_punishment' => 1,
            'time' => 130,
        ]);

        $match->juryScores()->create([
            'jury_number' => 2,
            'wiraga' => 65,
            'wirasa' => 61,
            'wirama' => 58,
            'total_score' => 184,
            'is_accepted' => false,
        ]);

        $this->actingAs($user)
            ->get(route('seni-streaming-online'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SeniStreamingOnline')
                ->has('arena')
                ->where('activeMatch.id', $match->id)
                ->where('activeMatch.status', 'ongoing')
                ->has('activeMatch.jury_scores', 1)
                ->where('activeMatch.jury_scores.0.total_score', '184.000')
                ->where('activeMatch.jury_scores.0.is_accepted', false)
                ->has('rankedMatches', 0)
            );
    }

    public function test_seni_streaming_online_hides_winner_rows_until_all_matches_are_ranked(): void
    {
        $streamer = Role::create(['name' => 'Streamer']);
        $user = User::factory()->create(['role_id' => $streamer->id]);

        SeniSingleMatch::create([
            'no_pool_babak_id' => 55,
            'bkp_id' => 3412,
            'matches_code' => 'S-03',
            'atletes' => 'Atlet C',
            'contingent' => 'Kontingen C',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putra',
            'status' => 'done',
            'is_active' => false,
            'is_passed' => true,
            'round_match' => 'Final',
            'no_order' => 2,
            'total_score' => 380,
            'rank' => 1,
        ]);

        SeniSingleMatch::create([
            'no_pool_babak_id' => 55,
            'bkp_id' => 3413,
            'matches_code' => 'S-04',
            'atletes' => 'Atlet D',
            'contingent' => 'Kontingen D',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putra',
            'status' => 'done',
            'is_active' => false,
            'is_passed' => false,
            'round_match' => 'Final',
            'no_order' => 1,
            'total_score' => 360,
            'rank' => null,
        ]);

        $this->actingAs($user)
            ->get(route('seni-streaming-online'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SeniStreamingOnline')
                ->has('rankedMatches', 0)
            );
    }

    public function test_seni_streaming_online_receives_winner_rows_when_all_matches_are_ranked(): void
    {
        $streamer = Role::create(['name' => 'Streamer']);
        $user = User::factory()->create(['role_id' => $streamer->id]);

        SeniSingleMatch::create([
            'no_pool_babak_id' => 55,
            'bkp_id' => 3412,
            'matches_code' => 'S-03',
            'atletes' => 'Atlet C',
            'contingent' => 'Kontingen C',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putra',
            'status' => 'done',
            'is_active' => false,
            'is_passed' => true,
            'round_match' => 'Final',
            'no_order' => 2,
            'total_score' => 380,
            'rank' => 1,
        ]);

        SeniSingleMatch::create([
            'no_pool_babak_id' => 55,
            'bkp_id' => 3413,
            'matches_code' => 'S-04',
            'atletes' => 'Atlet D',
            'contingent' => 'Kontingen D',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putra',
            'status' => 'done',
            'is_active' => false,
            'is_passed' => false,
            'round_match' => 'Final',
            'no_order' => 1,
            'total_score' => 360,
            'rank' => 2,
        ]);

        $this->actingAs($user)
            ->get(route('seni-streaming-online'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SeniStreamingOnline')
                ->has('rankedMatches', 2)
                ->where('rankedMatches.0.no_order', 1)
                ->where('rankedMatches.0.rank', 2)
                ->where('rankedMatches.1.no_order', 2)
                ->where('rankedMatches.1.rank', 1)
            );
    }

    public function test_non_streamers_cannot_view_seni_streaming_pages(): void
    {
        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);

        $this->actingAs($user)
            ->get(route('seni-streaming'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('seni-streaming-online'))
            ->assertForbidden();
    }
}
