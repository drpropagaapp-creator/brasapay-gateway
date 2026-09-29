<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformWhatsappOptOut extends Model
{
    protected $fillable = [
        'user_id',
        'phone',
        'source',
        'inbound_text',
    ];

    public static function isOptedOut(string $phone): bool
    {
        return static::query()->where('phone', $phone)->exists();
    }
}
