<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TreasuryRun extends Model
{
    protected $fillable = ['started_at', 'finished_at', 'status', 'counts', 'error', 'triggered_by'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'counts' => 'array',
        ];
    }
}
