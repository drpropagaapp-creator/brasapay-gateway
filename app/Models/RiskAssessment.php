<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Avaliação de risco do BrasaShield (observação: não bloqueia nada). */
class RiskAssessment extends Model
{
    protected $fillable = [
        'subject_type', 'subject_id', 'tenant_id', 'score', 'level', 'suggested_action', 'signals',
        'status', 'reviewed_by', 'reviewed_at', 'review_note', 'amount_cents',
    ];

    protected function casts(): array
    {
        return [
            'signals' => 'array',
            'score' => 'integer',
            'amount_cents' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }
}
