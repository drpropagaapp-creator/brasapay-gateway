<?php

namespace App\Models;

use App\Support\ApiWebhookEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ApiApplicationWebhook extends Model
{
    public const MAX_PER_APPLICATION = 3;

    protected $fillable = [
        'api_application_id',
        'name',
        'url',
        'secret',
        'events',
        'is_active',
        'environment',
    ];

    /** Ambiente do endpoint: 'live' recebe só eventos reais; 'sandbox' só eventos do sandbox (livemode false). */
    public function environmentName(): string
    {
        return $this->environment === ApiKey::ENV_SANDBOX ? ApiKey::ENV_SANDBOX : ApiKey::ENV_LIVE;
    }

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ApiApplication::class, 'api_application_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'api_application_webhook_product')
            ->withTimestamps();
    }

    public function isSubscribedTo(string $event): bool
    {
        $events = $this->events;
        if (! is_array($events) || $events === [] || ApiWebhookEvents::isAllSubscription($events)) {
            return true;
        }

        return in_array($event, $events, true);
    }

    /**
     * Sem produtos vinculados = todos. Eventos sem product_id (saques)
     * só saem em endpoints sem filtro.
     */
    public function appliesToProduct(mixed $productId): bool
    {
        $linked = $this->linkedProductIds();
        if ($linked === []) {
            return true;
        }
        if ($productId === null || $productId === '') {
            return false;
        }

        return in_array((string) $productId, $linked, true);
    }

    /**
     * @return list<string>
     */
    public function linkedProductIds(): array
    {
        if ($this->relationLoaded('products')) {
            return $this->products->pluck('id')->map(fn ($id) => (string) $id)->unique()->values()->all();
        }

        return $this->products()->pluck('products.id')->map(fn ($id) => (string) $id)->unique()->values()->all();
    }
}
