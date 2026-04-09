<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FightDetailJuryPointYellow extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function score()
    {
        return $this->belongsTo(RefScore::class, 'ref_score_id');
    }

    public function punishment()
    {
        return $this->belongsTo(RefPunishment::class, 'ref_punishment_id');
    }
}
