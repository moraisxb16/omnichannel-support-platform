<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per accepted state change. Append-only by convention: there is no
 * update path, because an audit trail that can be edited answers nothing.
 */
final class ConversationEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'conversation_id', 'conversation_reference', 'from_status', 'to_status',
        'actor', 'reason', 'context', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
