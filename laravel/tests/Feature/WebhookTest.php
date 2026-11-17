<?php

namespace Tests\Feature;

use App\Jobs\ProcessWebhookJob;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;


    public function test_webhook_is_received_and_stored(): void
    {
        $response = $this->postJson('/api/webhooks/stripe', [
            'event_id' => 'evt_001',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 1000, 'currency' => 'usd'],
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['id', 'status']);
        $this->assertEquals('pending', $response->json('status'));
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_001']);
    }

    public function test_webhook_requires_event_id(): void
    {
        $response = $this->postJson('/api/webhooks/stripe', [
            'type'    => 'payment.succeeded',
            'payload' => ['amount' => 1000],
        ]);

        $response->assertStatus(422);
    }

    public function test_webhook_requires_payload(): void
    {
        $response = $this->postJson('/api/webhooks/stripe', [
            'event_id' => 'evt_002',
            'type'     => 'payment.succeeded',
        ]);

        $response->assertStatus(422);
    }

    public function test_webhook_status_can_be_retrieved(): void
    {
        $event = WebhookEvent::create([
            'event_id' => 'evt_003',
            'provider' => 'stripe',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 500],
            'status'   => 'completed',
        ]);

        $response = $this->getJson("/api/webhooks/{$event->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'event_id' => 'evt_003',
            'status'   => 'completed',
        ]);
    }

    public function test_webhook_returns_404_for_unknown_id(): void
    {
        $response = $this->getJson('/api/webhooks/99999');

        $response->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Duplicates
    // ------------------------------------------------------------------

    public function test_duplicate_webhook_is_stored_only_once(): void
    {
        $body = [
            'event_id' => 'evt_dup_001',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 1000],
        ];

        $first  = $this->postJson('/api/webhooks/stripe', $body);
        $second = $this->postJson('/api/webhooks/stripe', $body);

        $first->assertStatus(202);
        $second->assertSuccessful();
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_duplicate_webhook_does_not_overwrite_existing_event(): void
    {
        $existing = WebhookEvent::create([
            'event_id' => 'evt_dup_002',
            'provider' => 'stripe',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 500],
            'status'   => 'completed',
        ]);

        $response = $this->postJson('/api/webhooks/stripe', [
            'event_id' => 'evt_dup_002',
            'type'     => 'payment.refunded',
            'payload'  => ['amount' => 9999],
        ]);

        $response->assertSuccessful();
        $this->assertSame($existing->id, $response->json('id'));
        $this->assertDatabaseCount('webhook_events', 1);

        $existing->refresh();
        $this->assertSame('completed', $existing->status);
        $this->assertSame('payment.succeeded', $existing->type);
        $this->assertSame(['amount' => 500], $existing->payload);
    }

    public function test_duplicate_webhook_is_only_queued_for_processing_once(): void
    {
        Queue::fake();

        $body = [
            'event_id' => 'evt_dup_003',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 1000],
        ];

        $this->postJson('/api/webhooks/stripe', $body)->assertStatus(202);
        $this->postJson('/api/webhooks/stripe', $body)->assertSuccessful();

        Queue::assertCount(1);
    }

    public function test_same_event_id_from_different_providers_is_allowed(): void
    {
        WebhookEvent::create([
            'event_id' => 'evt_shared',
            'provider' => 'stripe',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 100],
            'status'   => 'pending',
        ]);

        WebhookEvent::create([
            'event_id' => 'evt_shared',
            'provider' => 'paypal',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 100],
            'status'   => 'pending',
        ]);

        $this->assertDatabaseCount('webhook_events', 2);
    }

    public function test_same_event_id_from_different_providers_is_stored_separately_via_endpoint(): void
    {
        $body = [
            'event_id' => 'evt_cross',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 100],
        ];

        $stripe = $this->postJson('/api/webhooks/stripe', $body);
        $paypal = $this->postJson('/api/webhooks/paypal', $body);

        $stripe->assertStatus(202);
        $paypal->assertStatus(202);
        $this->assertNotSame($stripe->json('id'), $paypal->json('id'));
        $this->assertDatabaseCount('webhook_events', 2);
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_cross', 'provider' => 'stripe']);
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_cross', 'provider' => 'paypal']);
    }

    public function test_different_event_ids_from_same_provider_are_both_stored(): void
    {
        $this->postJson('/api/webhooks/stripe', [
            'event_id' => 'evt_a',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 100],
        ])->assertStatus(202);

        $this->postJson('/api/webhooks/stripe', [
            'event_id' => 'evt_b',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 200],
        ])->assertStatus(202);

        $this->assertDatabaseCount('webhook_events', 2);
    }

    public function test_database_rejects_duplicate_provider_and_event_id(): void
    {
        $attributes = [
            'event_id' => 'evt_db_dup',
            'provider' => 'stripe',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 100],
            'status'   => 'pending',
        ];

        WebhookEvent::create($attributes);

        $this->expectException(UniqueConstraintViolationException::class);

        WebhookEvent::create($attributes);
    }

    // ------------------------------------------------------------------
    // ProcessWebhookJob idempotency
    // ------------------------------------------------------------------

    public function test_job_processes_pending_event(): void
    {
        $event = $this->makeWebhookEvent('evt_job_pending', 'pending');

        $this->runJob($event);

        $this->assertSame('completed', $event->fresh()->status);
    }

    public function test_job_skips_event_that_is_already_completed(): void
    {
        $event = $this->makeWebhookEvent('evt_job_completed', 'completed');

        $this->assertJobSkips($event, 'completed');
    }

    public function test_job_skips_event_that_is_already_processing(): void
    {
        $event = $this->makeWebhookEvent('evt_job_processing', 'processing');

        $this->assertJobSkips($event, 'processing');
    }

    public function test_job_skips_event_claimed_by_another_worker_after_it_was_loaded(): void
    {
        $staleEvent = $this->makeWebhookEvent('evt_job_race', 'pending');

        WebhookEvent::whereKey($staleEvent->id)->update(['status' => 'processing']);

        $this->assertSame('pending', $staleEvent->status);

        $this->assertJobSkips($staleEvent, 'processing');
    }

    private function makeWebhookEvent(string $eventId, string $status): WebhookEvent
    {
        return WebhookEvent::create([
            'event_id' => $eventId,
            'provider' => 'stripe',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 1000],
            'status'   => $status,
        ]);
    }

    private function runJob(WebhookEvent $event): void
    {
        app()->call([new ProcessWebhookJob($event), 'handle']);
    }

    private function assertJobSkips(WebhookEvent $event, string $expectedStatus): void
    {
        $updatedAtBefore = WebhookEvent::findOrFail($event->id)->updated_at;

        $this->travel(5)->minutes();

        $this->runJob($event);

        $fresh = WebhookEvent::findOrFail($event->id);
        $this->assertSame($expectedStatus, $fresh->status);
        $this->assertTrue(
            $updatedAtBefore->equalTo($fresh->updated_at),
            'Job wrote to an event it should have skipped.'
        );
    }
}
