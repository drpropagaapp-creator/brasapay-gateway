<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerAcquirerPolicy extends Model
{
    public const MODE_INHERIT = 'inherit';

    public const MODE_RESTRICTED = 'restricted';

    public const MODE_DISABLED = 'disabled';

    /** Chaves de método iguais às de PaymentService::normalizedGatewayOrder. */
    public const METHODS = ['pix', 'card', 'boleto', 'pix_auto', 'open_finance', 'paypal', 'wallet'];

    protected $fillable = ['tenant_id', 'methods', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['methods' => 'array'];
    }
}
