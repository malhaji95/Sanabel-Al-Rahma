<?php

namespace App\Services;

use App\Models\AppNotification;

/**
 * In-app and email only (docs/07-decisions.md). Payloads carry a file number,
 * a reference and a link — never a name, ID, phone or wallet (rule 10).
 */
class NotificationService
{
    /** Keys a notification payload is allowed to carry. */
    private const SAFE_KEYS = [
        'ref', 'reason', 'file_number', 'period', 'due_date', 'expires_at', 'reference_no', 'id',
    ];

    public function send(?int $recipientId, string $templateKey, array $payload = [], array $channels = ['in_app', 'email']): void
    {
        if (! $recipientId) {
            return;
        }

        $safe = array_intersect_key($payload, array_flip(self::SAFE_KEYS));

        foreach ($channels as $channel) {
            AppNotification::create([
                'channel' => $channel,
                'recipient_id' => $recipientId,
                'template_key' => $templateKey,
                'payload_json' => $safe,
                'status' => 'queued',
            ]);
        }
    }

    public function render(AppNotification $notification): array
    {
        $payload = $notification->payload_json ?? [];
        $replace = [];

        foreach ($payload as $key => $value) {
            $replace[':'.$key] = (string) $value;
        }

        return [
            'subject' => __('notifications.'.$notification->template_key.'.subject'),
            'body' => strtr(__('notifications.'.$notification->template_key.'.body'), $replace),
            'url' => route('login'),
        ];
    }
}
