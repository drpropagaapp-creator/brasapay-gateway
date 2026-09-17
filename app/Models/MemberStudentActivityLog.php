<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberStudentActivityLog extends Model
{
    use Prunable;

    public const EVENT_LOGIN = 'login';

    public const EVENT_VISIT = 'visit';

    public const EVENT_LESSON_VIEWED = 'lesson_viewed';

    public const EVENT_LESSON_COMPLETED = 'lesson_completed';

    public const EVENT_MATERIAL_DOWNLOADED = 'material_downloaded';

    public const EVENT_ENROLLED = 'enrolled';

    public const EVENT_EXTERNAL_LINK_CLICKED = 'external_link_clicked';

    protected $fillable = [
        'user_id',
        'product_id',
        'member_lesson_id',
        'event',
        'subject',
        'file_index',
        'ip',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'file_index' => 'integer',
        ];
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subMonths(24));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(MemberLesson::class, 'member_lesson_id');
    }
}
