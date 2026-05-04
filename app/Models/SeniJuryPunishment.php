<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeniJuryPunishment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'waktu' => 'decimal:3',
            'keluar_garis' => 'decimal:3',
            'senjata_jatuh_atau_tidak_sesuai_deskripsi' => 'decimal:3',
            'senjata_tidak_jatuh_atau_tidak_sesuai_deskripsi' => 'decimal:3',
            'akeseoris_jatuh' => 'decimal:3',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(SeniSingleMatch::class, 'seni_single_match_id');
    }
}
