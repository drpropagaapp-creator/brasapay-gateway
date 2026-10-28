<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Versão de um template. Pacotes ficam no disco local privado, com hash para conferir na instalação. */
class MarketplaceTemplateVersion extends Model
{
    protected $fillable = [
        'template_id', 'version', 'package_path', 'package_sha256', 'content', 'summary', 'changelog',
        'status', 'rejection_reason', 'created_by', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['content' => 'array', 'summary' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function absolutePackagePath(): ?string
    {
        return $this->package_path ? Storage::disk('local')->path($this->package_path) : null;
    }
}
