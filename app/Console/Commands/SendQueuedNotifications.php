<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * T-34 — in-app and email only. In-app notifications are already readable the
 * moment they are queued; this command sends the email copies.
 */
class SendQueuedNotifications extends Command
{
    protected $signature = 'sanabel:send-notifications {--limit=200}';

    protected $description = 'Send queued email notifications and mark in-app ones as delivered';

    public function handle(NotificationService $notifications): int
    {
        $queued = AppNotification::query()
            ->where('status', 'queued')
            ->with('recipient')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($queued as $notification) {
            $rendered = $notifications->render($notification);

            if ($notification->channel === 'email' && $notification->recipient?->email) {
                // The body carries a file number and a link — never personal data.
                Mail::raw(
                    $rendered['body']."\n\n".$rendered['url'],
                    fn ($message) => $message
                        ->to($notification->recipient->email)
                        ->subject($rendered['subject'])
                );
            }

            $notification->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        }

        $this->info("Delivered {$queued->count()} notification(s).");

        return self::SUCCESS;
    }
}
