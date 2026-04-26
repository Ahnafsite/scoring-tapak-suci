<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $operatorRole = Role::where('name', 'Operator')->first();
        $sekretarisRole = Role::where('name', 'Sekretaris')->first();
        $juriRole = Role::where('name', 'Juri')->first();
        $streamerRole = Role::where('name', 'Streamer')->first();
        $timerRole = Role::where('name', 'Timer')->first();

        User::firstOrCreate(
            ['email' => 'operator@ema.id'],
            [
                'name' => 'Operator',
                'password' => Hash::make('ema.id'),
                'role_id' => $operatorRole->id,
            ]
        );

        User::firstOrCreate(
            ['email' => 'sekretaris@ema.id'],
            [
                'name' => 'Sekretaris',
                'password' => Hash::make('ema.id'),
                'role_id' => $sekretarisRole->id,
            ]
        );

        for ($i = 1; $i <= 5; $i++) {
            User::firstOrCreate(
                ['email' => 'juri'.$i.'@ema.id'],
                [
                    'name' => 'Juri '.$i,
                    'password' => Hash::make('ema.id'),
                    'role_id' => $juriRole->id,
                ]
            );
        }

        User::firstOrCreate(
            ['email' => 'streamer@ema.id'],
            [
                'name' => 'Streamer',
                'password' => Hash::make('ema.id'),
                'role_id' => $streamerRole->id,
            ]
        );

        User::firstOrCreate(
            ['email' => 'timer@ema.id'],
            [
                'name' => 'Timer',
                'password' => Hash::make('ema.id'),
                'role_id' => $timerRole->id,
            ]
        );
    }
}
