<?php

declare(strict_types=1);

namespace App\Actions\Messaging;

use App\Actions\Conversation\TransitionConversation;
use App\Domain\Conversation\ConversationStatus;
use App\Domain\Messaging\ReplyWindow;
use App\Domain\Messaging\SendKind;
use App\Models\Conversation;
use App\Models\Message;
use RuntimeException;

/**
 * An agent replies to a customer.
 *
 * The reply window is enforced HERE, before anything is stored, because the
 * alternative is worse than it looks: the provider rejects the send, the
 * message is already in our thread, and the agent believes they answered
 * someone who never heard from them. The customer waits for a reply that was
 * sent and silently dropped.
 *
 * Outside the window a template may still go out — the window restricts free
 * text, not contact. Refusing all outbound would be a stricter rule than the
 * channel actually imposes, and would strand conversations nobody can close.
 */
final class SendAgentReply
{
    public function __construct(
        private readonly ReplyWindow $window,
        private readonly TransitionConversation $transition,
    ) {
    }

    public function handle(
        Conversation $conversation,
        string $agentName,
        string $body,
        bool $isTemplate = false,
    ): Message {
        if ($conversation->status->isTerminal()) {
            throw new RuntimeException(
                "Conversation {$conversation->reference} is archived and cannot receive a reply."
            );
        }

        $allowed = $this->window->allowedSendKind(
            $conversation->last_customer_message_at?->toDateTimeImmutable(),
            now()->toDateTimeImmutable(),
        );

        if ($allowed === SendKind::TEMPLATE_ONLY && !$isTemplate) {
            throw new RuntimeException(sprintf(
                'Conversation %s is outside the %d-hour reply window; only an approved template may be sent.',
                $conversation->reference,
                $this->window->windowHours(),
            ));
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => Message::OUTBOUND,
            'author' => $agentName,
            'body' => $body,
            'send_kind' => $allowed->value,
            'occurred_at' => now(),
        ]);

        $conversation->update(['last_agent_message_at' => now()]);

        // The ball moves to the customer. Keeping it ASSIGNED would make the
        // agent look busy while they are in fact waiting, and the distributor
        // would under-use them for as long as the customer takes to answer.
        if ($conversation->status === ConversationStatus::ASSIGNED) {
            $this->transition->handle(
                $conversation,
                ConversationStatus::WAITING_CUSTOMER,
                'agent:' . $agentName,
                'agent replied',
            );
        }

        return $message;
    }
}
