<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Presence\PresenceState;
use App\Domain\Routing\AgentWorkload;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property PresenceState $presence
 * @property int $capacity
 */
final class Agent extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'presence', 'capacity', 'last_assigned_at', 'last_seen_at', 'skills'];

    protected function casts(): array
    {
        return [
            'presence' => PresenceState::class,
            'capacity' => 'integer',
            'last_assigned_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'skills' => 'array',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Freeze this agent into the value object the distributor reasons about.
     *
     * The conversion happens here, once, so RoundRobinDistributor never touches
     * Eloquent and can be tested with plain objects. $openConversations is
     * passed in rather than counted here: the caller loads every agent's count
     * in one query, and letting each model count its own would make routing N+1.
     */
    public function toWorkload(int $openConversations): AgentWorkload
    {
        return new AgentWorkload(
            agentId: $this->id,
            name: $this->name,
            presence: $this->presence,
            openConversations: $openConversations,
            capacity: $this->capacity,
            lastAssignedAtTimestamp: $this->last_assigned_at?->getTimestamp(),
            skills: $this->skills ?? [],
        );
    }
}
