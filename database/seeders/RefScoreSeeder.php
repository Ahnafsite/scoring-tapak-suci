<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RefScoreSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $scores = [
            ['name' => '20', 'score' => 20],
            ['name' => '10+20', 'score' => 30],
            ['name' => '30', 'score' => 30],
            ['name' => '10+30', 'score' => 40],
            ['name' => '40', 'score' => 40],
            ['name' => '10+40', 'score' => 50],
        ];

        foreach ($scores as $score) {
            \App\Models\RefScore::create($score);
        }
    }
}
