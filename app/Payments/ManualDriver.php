<?php

namespace App\Payments;

use App\Exceptions\DuplicateTransactionRef;
use App\Models\Donation;
use App\Services\DonationService;

/**
 * The only driver in phase 1. Money is transferred outside the platform;
 * this driver only records and verifies it.
 */
class ManualDriver implements PaymentGateway
{
    public function __construct(private readonly DonationService $donations) {}

    public function record(array $payload): Donation
    {
        return $this->donations->record($payload);
    }

    public function verify(Donation $donation, int $verifierId): Donation
    {
        return $this->donations->verify($donation, $verifierId);
    }

    public function reject(Donation $donation, int $verifierId, string $reason): Donation
    {
        return $this->donations->reject($donation, $verifierId, $reason);
    }

    public function key(): string
    {
        return 'manual';
    }
}
