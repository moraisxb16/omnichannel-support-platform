<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Messaging\RecordInboundMessage;
use App\Actions\Routing\RouteQueuedConversations;
use App\Domain\Presence\PresenceState;
use App\Models\Agent;
use Illuminate\Database\Seeder;

/**
 * Synthetic data for the inbox, chosen so the interesting states are all
 * visible on first load rather than only after someone goes looking.
 *
 * Every name here is invented. Nothing in this file corresponds to a real
 * person, customer or company.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $agents = [
            // Deliberately uneven ceilings: the whole point of comparing load
            // by ratio rather than by count is invisible when everyone has the
            // same number.
            ['name' => 'Alice Nakamura', 'capacity' => 6, 'presence' => PresenceState::ONLINE, 'skills' => ['billing']],
            ['name' => 'Bruno Okafor', 'capacity' => 4, 'presence' => PresenceState::ONLINE, 'skills' => ['billing', 'technical']],
            ['name' => 'Cora Lindqvist', 'capacity' => 2, 'presence' => PresenceState::ONLINE, 'skills' => ['technical']],
            // Away, so the inbox shows an agent who is present but unroutable.
            ['name' => 'Davi Marchetti', 'capacity' => 5, 'presence' => PresenceState::AWAY, 'skills' => []],
            // Offline, so the orphan sweep has something to find.
            ['name' => 'Elena Brandt', 'capacity' => 5, 'presence' => PresenceState::OFFLINE, 'skills' => []],
        ];

        foreach ($agents as $agent) {
            Agent::create([
                'name' => $agent['name'],
                'email' => str($agent['name'])->lower()->replace(' ', '.')->append('@beacon.invalid')->toString(),
                'presence' => $agent['presence'],
                'capacity' => $agent['capacity'],
                'last_seen_at' => now(),
                'skills' => $agent['skills'],
            ]);
        }

        $conversations = [
            ['widget', 'cus-1001', 'Noor Haddad', 'My order says delivered but nothing arrived.', 'technical'],
            ['widget', 'cus-1002', 'Teo Vasquez', 'I was charged twice for the same order.', 'billing'],
            ['email', 'cus-1003', 'Priya Raman', 'Can I change the delivery address?', null],
            ['widget', 'cus-1004', 'Jonas Weber', 'The discount code did not apply at checkout.', 'billing'],
            ['social', 'cus-1005', 'Amara Diallo', 'How long does a return take?', null],
            ['email', 'cus-1006', 'Luca Ferraro', 'I need an invoice for my company.', 'billing'],
            ['widget', 'cus-1007', 'Mei Tanaka', 'The product arrived damaged.', 'technical'],
            ['social', 'cus-1008', 'Ravi Chandra', 'Is this compatible with my model?', 'technical'],
        ];

        $record = app(RecordInboundMessage::class);

        foreach ($conversations as [$channel, $ref, $name, $body, $skill]) {
            $result = $record->handle($channel, $ref, $body, 'seed-' . $ref, $name, $skill);
            $result['conversation']->update(['subject' => str($body)->limit(48)->toString()]);
        }

        // Route most of them, leaving a couple queued so the dashboard shows a
        // real queue with a real reason rather than a tidy empty state.
        app(RouteQueuedConversations::class)->handle(limit: 6);
    }
}
