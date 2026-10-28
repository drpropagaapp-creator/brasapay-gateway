<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImport extends Model
{
    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_READY = 'ready';

    public const STATUS_IMPORTING = 'importing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid', 'tenant_id', 'user_id', 'status', 'package_path', 'package_sha256',
        'original_name', 'summary', 'result', 'error',
    ];

    protected function casts(): array
    {
        return ['summary' => 'array', 'result' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** Pacotes ficam no disco local privado (nunca no storage público). */
    public function absolutePackagePath(): ?string
    {
        return $this->package_path ? storage_path('app/'.$this->package_path) : null;
    }
}
