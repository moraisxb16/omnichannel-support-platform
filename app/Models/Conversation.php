<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Conversation\ConversationStatus;
use App\Domain\Messaging\ReplyWindow;
use App\Domain\Messaging\SendKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $reference
 * @property ConversationStatus $status
 * @property ?int $agent_id
 */
final class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference', 'channel', 'status', 'agent_id', 'customer_ref', 'customer_name',
        'subject', 'required_skill', 'last_customer_message_at', 'last_agent_message_at',
        'queued_at', 'assigned_at', 'resolved_at', 'no_agent_reason', 'routing_attempts',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'last_customer_message_at' => 'immutable_datetime',
            'last_agent_message_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'assigned_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'routing_attempts' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ConversationEvent::class);
    }

    /**
     * What an agent may send right now.
     *
     * Exposed on the model so the inbox can render the right composer before
     * anyone types, rather than discovering the window closed at send time —
     * by which point the agent has written a reply that will never arrive.
     */
    public function allowedSendKind(?ReplyWindow $window = null): SendKind
    {
        $window ??= app(ReplyWindow::class);

        return $window->allowedSendKind(
            $this->last_customer_message_at?->toDateTimeImmutable(),
            now()->toDateTimeImmutable(),
        );
    }

    public function replyWindowSecondsRemaining(?ReplyWindow $window = null): int
    {
        $window ??= app(ReplyWindow::class);

        return $window->secondsRemaining(
            $this->last_customer_message_at?->toDateTimeImmutable(),
            now()->toDateTimeImmutable(),
        );
    }

    /**
     * A conversation nobody owns and nobody is going to pick up on their own.
     *
     * The limbo case: it is assigned, but to an agent who is no longer there.
     * Nothing in the normal flow rescues it, because it is not in the queue —
     * which is exactly why the sweep exists.
     */
    public function isOrphaned(): bool
    {
        return $this->status->hasOwner()
            && ($this->agent === null || $this->agent->presence->releasesHeldWork());
    }
}
