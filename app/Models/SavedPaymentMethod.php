<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedPaymentMethod extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'gateway',
        'gateway_payment_method_id',
        'last_four',
        'brand',
        'type',
        // Cartão salvo pela API (aplicação do seller): cliente na adquirente, dono e pedido de origem.
        'gateway_customer_id',
        'api_application_id',
        'source_order_id',
        'exp_month',
        'exp_year',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query->whereNull('tenant_id');
        }
        return $query->where('tenant_id', $tenantId);
    }
}
