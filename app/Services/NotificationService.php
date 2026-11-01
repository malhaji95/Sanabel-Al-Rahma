<?php

namespace App\Services;

use App\Models\AppNotification;

/**
 * In-app and email only (docs/07-decisions.md). Payloads carry a file number,
 * a reference and a link — never a name, ID, phone or wallet (rule 10).
 */
class NotificationService
{
    /**
     * Keys a notification payload is allowed to carry.
     *
     * T-224 — «تم تحويل لك مثلا مبلغ, كل فقير حسب المبلغ اللي له: ميت ألف,
     * خمسين ألف, ميتين ألف حسب المبلغ», 28 October, in the association's own
     * recording. T-141 had kept every figure out of these bodies, and that
     * was right for the five messages it was written for: they go to staff,
     * about other people's money, and a reference number is all a desk needs
     * to find the row.
     *
     * This one does not. It goes to the household about the household's own
     * transfer, and the association asked for the figure in it, because a
     * family cannot confirm a sum nobody told them.
     *
     * So the key is allowed and no message gains it by itself: a template
     * carries a figure only where its caller passes one, and the five staff
     * messages pass none.
     */
    private const SAFE_KEYS = [
        'ref', 'reason', 'file_number', 'period', 'due_date', 'expires_at', 'reference_no', 'id',
        'amount',
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
