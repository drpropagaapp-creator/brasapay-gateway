<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceInstall extends Model
{
    protected $fillable = ['template_id', 'version_id', 'tenant_id', 'user_id', 'product_import_id', 'status'];
}
