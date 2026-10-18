<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboundEmail extends Model
{
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'token',
        'user_id',
        'tenant_id',
        'recipient_email',
        'recipient_name',
        'recipient_role',
        'subject',
        'mail_label',
        'mailable',
        'mailer',
        'status',
        'error_message',
        'sent_at',
        'opened_at',
        'open_count',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'open_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function displayStatus(): string
    {
        if ($this->opened_at !== null) {
            return 'opened';
        }

        return (string) $this->status;
    }
}
