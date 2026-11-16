<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MemberLesson extends Model
{
    public const TYPE_VIDEO = 'video';
    public const TYPE_LINK = 'link';
    public const TYPE_PDF = 'pdf';
    public const TYPE_TEXT = 'text';

    protected $fillable = [
        'member_module_id',
        'product_id',
        'title',
        'position',
        'type',
        'content_url',
        'link_title',
        'content_files',
        'release_after_days',
        'release_at_date',
        'content_text',
        'duration_seconds',
        'is_free',
        'watermark_enabled',
        'watermark_header_footer',
        'watermark_center',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'duration_seconds' => 'integer',
            'is_free' => 'boolean',
            'watermark_enabled' => 'boolean',
            'watermark_header_footer' => 'boolean',
            'watermark_center' => 'boolean',
            'content_files' => 'array',
            'release_after_days' => 'integer',
            'release_at_date' => 'date:Y-m-d',
        ];
    }

    /**
     * @return array{header_footer: bool, center: bool}
     */
    public function pdfDrmMarks(): array
    {
        return [
            'header_footer' => (bool) ($this->watermark_header_footer ?? true),
            'center' => (bool) ($this->watermark_center ?? true),
        ];
    }

    public function appliesPdfDrm(): bool
    {
        if (! ($this->watermark_enabled ?? false)) {
            return false;
        }

        $marks = $this->pdfDrmMarks();

        return $marks['header_footer'] || $marks['center'];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(MemberModule::class, 'member_module_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(MemberComment::class, 'member_lesson_id');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(MemberLessonRating::class, 'member_lesson_id');
    }
}
