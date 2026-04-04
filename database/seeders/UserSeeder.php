<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $operatorRole = \App\Models\Role::where('name', 'Operator')->first();
        $sekretarisRole = \App\Models\Role::where('name', 'Sekretaris')->first();
        $juriRole = \App\Models\Role::where('name', 'Juri')->first();
        $streamerRole = \App\Models\Role::where('name', 'Streamer')->first();

        \App\Models\User::firstOrCreate(
            ['email' => 'operator@ema.id'],
            [
                'name' => 'Operator',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role_id' => $operatorRole->id,
            ]
        );

        \App\Models\User::firstOrCreate(
            ['email' => 'sekretaris@ema.id'],
            [
                'name' => 'Sekretaris',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role_id' => $sekretarisRole->id,
            ]
        );

        for ($i = 1; $i <= 5; $i++) {
            \App\Models\User::firstOrCreate(
                ['email' => 'juri' . $i . '@ema.id'],
                [
                    'name' => 'Juri ' . $i,
                    'password' => \Illuminate\Support\Facades\Hash::make('password'),
                    'role_id' => $juriRole->id,
                ]
            );
        }

        \App\Models\User::firstOrCreate(
            ['email' => 'streamer@ema.id'],
            [
                'name' => 'Streamer',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role_id' => $streamerRole->id,
            ]
        );
    }
}
