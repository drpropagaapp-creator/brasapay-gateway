<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UazapiCampaign extends Model
{
    public const AUDIENCE_ABANDONED_CART = 'abandoned_cart';

    public const AUDIENCE_PENDING_PIX = 'pending_pix';

    public const AUDIENCE_BUYERS = 'buyers';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /**
     * @return list<string>
     */
    public static function audiences(): array
    {
        return [
            self::AUDIENCE_ABANDONED_CART,
            self::AUDIENCE_PENDING_PIX,
            self::AUDIENCE_BUYERS,
        ];
    }

    protected $fillable = [
        'tenant_id',
        'uazapi_instance_id',
        'audience',
        'message',
        'include_image',
        'status',
        'queued_count',
        'skipped_count',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'include_image' => 'boolean',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(UazapiInstance::class, 'uazapi_instance_id');
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(UazapiMessageDispatch::class, 'campaign_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'audience' => $this->audience,
            'message' => $this->message,
            'include_image' => (bool) $this->include_image,
            'status' => $this->status,
            'queued_count' => (int) $this->queued_count,
            'skipped_count' => (int) $this->skipped_count,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
