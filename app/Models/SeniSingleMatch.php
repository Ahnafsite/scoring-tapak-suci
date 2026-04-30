<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeniSingleMatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_disqualified' => 'boolean',
            'is_passed' => 'boolean',
            'total_score' => 'decimal:3',
            'total_wiraga' => 'decimal:3',
            'total_wirasa' => 'decimal:3',
            'total_wirama' => 'decimal:3',
            'total_kualitas_teknik' => 'decimal:3',
            'total_kuantitas_teknik' => 'decimal:3',
            'total_ketangkasan' => 'decimal:3',
            'total_stamina' => 'decimal:3',
            'total_kemantapan' => 'decimal:3',
            'total_musik' => 'decimal:3',
            'total_punishment' => 'decimal:3',
        ];
    }

    public function juryScores(): HasMany
    {
        return $this->hasMany(SeniJuryScore::class);
    }
}
