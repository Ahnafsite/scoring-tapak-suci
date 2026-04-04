<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ArenaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\Arena::firstOrCreate(
            ['id' => 1],
            [
                'championship_name' => 'Kejuaraan Tapak Suci',
                'arena_name' => 'Gelanggang A',
                'operator_id' => \App\Models\User::where('email', 'operator@ema.id')->first()?->id,
                'sekretaris_id' => \App\Models\User::where('email', 'sekretaris@ema.id')->first()?->id,
                'jury_one_id' => \App\Models\User::where('email', 'juri1@ema.id')->first()?->id,
                'jury_two_id' => \App\Models\User::where('email', 'juri2@ema.id')->first()?->id,
                'jury_three_id' => \App\Models\User::where('email', 'juri3@ema.id')->first()?->id,
                'jury_four_id' => \App\Models\User::where('email', 'juri4@ema.id')->first()?->id,
                'jury_five_id' => \App\Models\User::where('email', 'juri5@ema.id')->first()?->id,
                'streamer_id' => \App\Models\User::where('email', 'streamer@ema.id')->first()?->id,
            ]
        );
    }
}
