<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public WebhookEvent $event) {}

    public function handle(): void
    {
        $claimed = WebhookEvent::whereKey($this->event->id)
            ->where('status', 'pending')
            ->update(['status' => 'processing']);

        // TODO: What happens if two jobs process the same event simultaneously?
        if ($claimed === 0) {
            return;
        }

        $this->event->refresh();

        // Simulate processing work
        try {
            sleep(1);
        } catch (\Throwable $e) {
            WebhookEvent::whereKey($this->event->id)->update(['status' => 'pending']);
            throw $e;
        }

        WebhookEvent::whereKey($this->event->id)->update(['status' => 'completed']);
    }

    public function failed(\Throwable $e): void
    {
        $this->event->update([
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
        ]);
    }
}
