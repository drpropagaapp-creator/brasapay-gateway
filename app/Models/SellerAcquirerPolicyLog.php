<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerAcquirerPolicyLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'actor_user_id', 'before', 'after', 'reason'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }
}
