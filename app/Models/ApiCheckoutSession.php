<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiCheckoutSession extends Model
{
    protected $fillable = [
        'api_application_id',
        'tenant_id',
        'session_token',
        'customer',
        'amount',
        'currency',
        'product_id',
        'product_offer_id',
        'subscription_plan_id',
        'metadata',
        'return_url',
        'expires_at',
        'order_id',
        'save_card',
        'payment_methods',
    ];

    protected function casts(): array
    {
        return [
            'save_card' => 'boolean',
            'payment_methods' => 'array',
            'customer' => 'array',
            'amount' => 'decimal:2',
            'metadata' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function apiApplication(): BelongsTo
    {
        return $this->belongsTo(ApiApplication::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query->whereNull('tenant_id');
        }
        return $query->where('tenant_id', $tenantId);
    }

    /** Métodos que o sistema do seller pediu para mostrar (vazio = todos os configurados). */
    public function allowsMethod(string $method): bool
    {
        $list = $this->payment_methods;

        return ! is_array($list) || $list === [] || in_array($method, $list, true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
