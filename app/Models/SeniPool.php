<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeniPool extends Model
{
    protected $guarded = [];

    public function matches(): HasMany
    {
        return $this->hasMany(SeniSingleMatch::class, 'no_pool_babak_id', 'no_pool_babak_id');
    }
}
