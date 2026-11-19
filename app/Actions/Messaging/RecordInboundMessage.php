<?php

declare(strict_types=1);

namespace App\Actions\Messaging;

use App\Actions\Conversation\TransitionConversation;
use App\Domain\Conversation\ConversationStatus;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Message;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Takes one inbound message from a channel provider and files it.
 *
 * Idempotent on `messages.provider_message_id`, decided by the UNIQUE
 * constraint rather than a SELECT beforehand. Providers redeliver on purpose:
 * a webhook that is not acknowledged quickly enough comes again, and the gap
 * between a check and an insert is more than wide enough to find. Without the
 * constraint the customer sees their own message twice and the agent answers it
 * twice.
 *
 * An inbound message also does two things beyond being stored:
 *
 *  - It reopens the reply window, which runs from the customer's LAST message.
 *  - It brings a resolved conversation back to life. Someone replying to say
 *    the answer did not work belongs in the same thread, not in a new one with
 *    no history for whoever picks it up.
 */
final class RecordInboundMessage
{
    public function __construct(
        private readonly TransitionConversation $transition,
    ) {
    }

    /**
     * @return array{conversation:Conversation,message:?Message,duplicate:bool,reopened:bool}
     */
    public function handle(
        string $channel,
        string $customerRef,
        string $body,
        string $providerMessageId,
        ?string $customerName = null,
        ?string $requiredSkill = null,
    ): array {
        $conversation = $this->findOrStartConversation($channel, $customerRef, $customerName, $requiredSkill);

        try {
            $message = DB::transaction(fn (): Message => Message::create([
                'conversation_id' => $conversation->id,
                'direction' => Message::INBOUND,
                'provider_message_id' => $providerMessageId,
                'author' => $customerName ?? $customerRef,
                'body' => $body,
                'occurred_at' => now(),
            ]));
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                // Already filed. Nothing else may run: re-opening the window or
                // reviving the conversation a second time would both be wrong.
                return [
                    'conversation' => $conversation->refresh(),
                    'message' => null,
                    'duplicate' => true,
                    'reopened' => false,
                ];
            }
            throw $e;
        }

        $conversation->update(['last_customer_message_at' => now()]);

        $reopened = false;

        if ($conversation->status === ConversationStatus::RESOLVED) {
            $this->transition->handle(
                $conversation,
                ConversationStatus::QUEUED,
                'customer',
                'customer replied after resolution',
            );
            $reopened = true;
        } elseif ($conversation->status === ConversationStatus::WAITING_CUSTOMER) {
            // The ball comes back to the agent who already owns it. Deliberately
            // not re-queued: they have the context, and bouncing it to whoever
            // is free throws that away.
            $this->transition->handle(
                $conversation,
                ConversationStatus::ASSIGNED,
                'customer',
                'customer replied',
            );
        }

        return [
            'conversation' => $conversation->refresh(),
            'message' => $message,
            'duplicate' => false,
            'reopened' => $reopened,
        ];
    }

    private function findOrStartConversation(
        string $channel,
        string $customerRef,
        ?string $customerName,
        ?string $requiredSkill,
    ): Conversation {
        // An open conversation on the same channel with the same customer is
        // the same conversation. Starting a new one per message would split a
        // single exchange across several inboxes.
        $existing = Conversation::query()
            ->where('channel', $channel)
            ->where('customer_ref', $customerRef)
            ->whereNotIn('status', [ConversationStatus::ARCHIVED])
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $conversation = Conversation::create([
            'reference' => 'CV-' . strtoupper(Str::random(8)),
            'channel' => $channel,
            'status' => ConversationStatus::QUEUED,
            'customer_ref' => $customerRef,
            'customer_name' => $customerName,
            'required_skill' => $requiredSkill,
            'queued_at' => now(),
        ]);

        // The timeline starts at creation, not at the first transition.
        // Without this entry the history begins mid-story and "how did this
        // conversation start?" has no answer -- which is exactly the question
        // someone asks when a thread turns up in the wrong queue.
        ConversationEvent::create([
            'conversation_id' => $conversation->id,
            'conversation_reference' => $conversation->reference,
            'from_status' => null,
            'to_status' => ConversationStatus::QUEUED->value,
            'actor' => 'customer',
            'reason' => 'conversation opened on ' . $channel,
            'occurred_at' => now(),
        ]);

        return $conversation;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique') || str_contains($message, 'duplicate');
    }
}
