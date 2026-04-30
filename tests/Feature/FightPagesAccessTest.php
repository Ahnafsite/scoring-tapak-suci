<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FightPagesAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_are_redirected_to_login_for_all_fight_pages(): void
    {
        $this->get(route('fight-match-control'))->assertRedirect(route('login'));
        $this->get(route('fight-secretary'))->assertRedirect(route('login'));
        $this->get(route('fight-jury'))->assertRedirect(route('login'));
    }

    #[Test]
    public function operator_can_view_the_fight_match_control_page(): void
    {
        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);

        $this->actingAs($user)
            ->get(route('fight-match-control'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('FightMatchControl')
                ->has('schedules')
                ->has('arena')
                ->has('activeMatch')
                ->has('recapJuryPoint')
            );
    }

    #[Test]
    public function secretary_can_view_the_fight_secretary_page(): void
    {
        $secretary = Role::create(['name' => 'Sekretaris']);
        $user = User::factory()->create(['role_id' => $secretary->id]);

        $this->actingAs($user)
            ->get(route('fight-secretary'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('FightSecretary')
                ->has('arena')
                ->has('activeMatch')
                ->has('recapPoints')
                ->has('yellowPoints')
                ->has('bluePoints')
            );
    }

    #[Test]
    public function jury_can_view_the_fight_jury_page(): void
    {
        $jury = Role::create(['name' => 'Juri']);
        $user = User::factory()->create(['role_id' => $jury->id]);

        $this->actingAs($user)
            ->get(route('fight-jury'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('FightJury')
                ->has('arena')
                ->has('activeMatch')
                ->has('recapPoints')
                ->has('yellowPoints')
                ->has('bluePoints')
            );
    }

    #[Test]
    public function users_with_the_wrong_role_cannot_view_other_fight_pages(): void
    {
        $operator = Role::create(['name' => 'Operator']);
        $jury = Role::create(['name' => 'Juri']);
        $secretary = Role::create(['name' => 'Sekretaris']);

        $operatorUser = User::factory()->create(['role_id' => $operator->id]);
        $juryUser = User::factory()->create(['role_id' => $jury->id]);
        $secretaryUser = User::factory()->create(['role_id' => $secretary->id]);

        $this->actingAs($operatorUser)
            ->get(route('fight-secretary'))
            ->assertForbidden();

        $this->actingAs($juryUser)
            ->get(route('fight-match-control'))
            ->assertForbidden();

        $this->actingAs($secretaryUser)
            ->get(route('fight-jury'))
            ->assertForbidden();
    }
}
