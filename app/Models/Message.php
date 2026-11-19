<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Message extends Model
{
    use HasFactory;

    public const INBOUND = 'inbound';
    public const OUTBOUND = 'outbound';

    protected $fillable = [
        'conversation_id', 'direction', 'provider_message_id',
        'author', 'body', 'send_kind', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isInbound(): bool
    {
        return $this->direction === self::INBOUND;
    }
}
