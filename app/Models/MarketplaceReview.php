<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Avaliação de template: só de quem instalou, uma por conta, publicada após moderação. */
class MarketplaceReview extends Model
{
    protected $fillable = ['template_id', 'tenant_id', 'user_id', 'rating', 'comment', 'status', 'moderated_by'];
}
