<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FightMatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'winner_status' => \App\Enums\WinnerStatus::class,
        ];
    }
}
