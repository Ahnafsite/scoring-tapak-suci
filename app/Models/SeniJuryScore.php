<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeniJuryScore extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'wiraga' => 'decimal:3',
            'wirasa' => 'decimal:3',
            'wirama' => 'decimal:3',
            'kualitas_teknik' => 'decimal:3',
            'kuantitas_teknik' => 'decimal:3',
            'ketangkasan' => 'decimal:3',
            'stamina' => 'decimal:3',
            'kemantapan' => 'decimal:3',
            'musik' => 'decimal:3',
            'total_score' => 'decimal:3',
            'is_accepted' => 'boolean',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(SeniSingleMatch::class, 'seni_single_match_id');
    }
}
