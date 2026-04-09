<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RefPunishmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $punishments = [
            ['name' => '-10', 'score' => 20],
            ['name' => '-20', 'score' => 20],
            ['name' => '-30', 'score' => 30],
            ['name' => '-40', 'score' => 40],
        ];

        foreach ($punishments as $punishment) {
            \App\Models\RefPunishment::create($punishment);
        }
    }
}
