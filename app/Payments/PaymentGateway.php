<?php

namespace App\Payments;

use App\Models\Donation;

/**
 * Rule 7 — the one interface allowed to exist with a single implementation.
 * No automatic transfers exist in phase 1: a human moves the money and the
 * system records it.
 */
interface PaymentGateway
{
    /** Records an intent to pay. Never moves money. */
    public function record(array $payload): Donation;

    /** Marks money as actually received, after a human has checked the proof. */
    public function verify(Donation $donation, int $verifierId): Donation;

    public function reject(Donation $donation, int $verifierId, string $reason): Donation;

    public function key(): string;
}
