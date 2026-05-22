<?php

namespace Tests\Feature;

use App\Models\Arena;
use App\Models\Role;
use App\Models\SeniSingleMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeniSecretaryAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function secretary_can_view_the_seni_secretary_page(): void
    {
        $secretary = Role::create(['name' => 'Sekretaris']);
        $user = User::factory()->create(['role_id' => $secretary->id]);

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
            ->get(route('seni-secretary'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SeniSecretary')
                ->has('arena')
                ->where('activeMatch.id', $match->id)
                ->where('activeMatch.total_score', '378.000')
                ->where('activeMatch.total_wiraga', '56.000')
                ->where('activeMatch.total_wirama', '54.000')
                ->where('activeMatch.total_punishment', '2.000')
                ->where('activeMatch.time', 140)
                ->has('activeMatch.jury_scores', 1)
                ->where('activeMatch.jury_scores.0.wiraga', '70.000')
                ->where('activeMatch.jury_scores.0.wirasa', '60.000')
                ->where('activeMatch.jury_scores.0.wirama', '50.000')
                ->where('activeMatch.jury_scores.0.total_score', '180.000')
                ->has('activeMatch.jury_punishments', 1)
                ->where('activeMatch.jury_punishments.0.keluar_garis', '2.000')
            );
    }

    #[Test]
    public function non_secretary_cannot_view_the_seni_secretary_page(): void
    {
        $jury = Role::create(['name' => 'Juri']);
        $user = User::factory()->create(['role_id' => $jury->id]);

        $this->actingAs($user)
            ->get(route('seni-secretary'))
            ->assertForbidden();
    }

    #[Test]
    public function secretary_receives_three_active_seni_juries(): void
    {
        $secretary = Role::create(['name' => 'Sekretaris']);
        $jury = Role::create(['name' => 'Juri']);
        $user = User::factory()->create(['role_id' => $secretary->id]);
        $juries = User::factory()
            ->count(3)
            ->sequence(
                ['name' => 'Juri 5'],
                ['name' => 'Juri 1'],
                ['name' => 'Juri 3'],
            )
            ->create(['role_id' => $jury->id]);

        DB::table('sessions')->insert($juries->map(fn (User $activeJury): array => [
            'id' => 'active-jury-'.$activeJury->id,
            'user_id' => $activeJury->id,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ])->all());

        $this->actingAs($user)
            ->get(route('seni-secretary'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeJuries', [1, 3, 5])
            );
    }

    #[Test]
    public function secretary_receives_ranked_matches_when_all_seni_matches_are_done_and_ranked(): void
    {
        $secretary = Role::create(['name' => 'Sekretaris']);
        $user = User::factory()->create(['role_id' => $secretary->id]);

        SeniSingleMatch::create([
            'no_pool_babak_id' => 55,
            'bkp_id' => 3410,
            'matches_code' => 'S-01',
            'atletes' => 'Atlet A',
            'contingent' => 'Kontingen A',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putra',
            'status' => 'done',
            'is_active' => false,
            'is_passed' => true,
            'round_match' => 'Final',
            'no_order' => 2,
            'total_score' => 378,
            'rank' => 1,
        ]);
        SeniSingleMatch::create([
            'no_pool_babak_id' => 55,
            'bkp_id' => 3411,
            'matches_code' => 'S-02',
            'atletes' => 'Atlet B',
            'contingent' => 'Kontingen B',
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
            ->get(route('seni-secretary'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SeniSecretary')
                ->has('rankedMatches', 2)
                ->where('rankedMatches.0.no_order', 1)
                ->where('rankedMatches.0.rank', 2)
                ->where('rankedMatches.0.is_passed', false)
                ->where('rankedMatches.1.no_order', 2)
                ->where('rankedMatches.1.rank', 1)
                ->where('rankedMatches.1.is_passed', true)
            );
    }

    #[Test]
    public function secretary_does_not_receive_ranked_matches_when_a_ranked_match_is_ongoing(): void
    {
        $secretary = Role::create(['name' => 'Sekretaris']);
        $user = User::factory()->create(['role_id' => $secretary->id]);

        $activeMatch = SeniSingleMatch::create([
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
            'is_passed' => true,
            'round_match' => 'Final',
            'no_order' => 1,
            'total_score' => 378,
            'rank' => 1,
        ]);

        SeniSingleMatch::create([
            'no_pool_babak_id' => 55,
            'bkp_id' => 3411,
            'matches_code' => 'S-02',
            'atletes' => 'Atlet B',
            'contingent' => 'Kontingen B',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putra',
            'status' => 'done',
            'is_active' => false,
            'is_passed' => false,
            'round_match' => 'Final',
            'no_order' => 2,
            'total_score' => 360,
            'rank' => 2,
        ]);

        $this->actingAs($user)
            ->get(route('seni-secretary'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SeniSecretary')
                ->where('activeMatch.id', $activeMatch->id)
                ->where('activeMatch.status', 'ongoing')
                ->has('rankedMatches', 0)
            );
    }
}
