<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Template da biblioteca (Brasa Marketplace). */
class MarketplaceTemplate extends Model
{
    public const KIND_PACKAGE = 'product_package';

    public const KIND_RECOVERY = 'recovery';

    public const STATUS_PENDING = 'pending_review';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_UNPUBLISHED = 'unpublished';

    /** Categorias do catálogo. Os tipos de produto são pacotes oficiais; recuperação/comunicação são textos. */
    public const CATEGORIES = [
        'checkout' => 'Checkout',
        'landing' => 'Landing page',
        'funnel' => 'Funil',
        'product' => 'Modelo de produto',
        'offers' => 'Estrutura de ofertas',
        'kit' => 'Kit comercial completo',
        'recovery' => 'Recuperação de vendas',
    ];

    protected $fillable = [
        'kind', 'category', 'title', 'slug', 'tagline', 'description', 'author_tenant_id', 'author_name', 'is_official',
        'status', 'current_version_id', 'price_cents', 'installs_count', 'rating_count', 'rating_sum', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return ['is_official' => 'boolean', 'price_cents' => 'integer'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MarketplaceTemplateVersion::class, 'template_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(MarketplaceTemplateVersion::class, 'current_version_id');
    }

    public function ratingAverage(): ?float
    {
        return $this->rating_count > 0 ? round($this->rating_sum / $this->rating_count, 1) : null;
    }
}
