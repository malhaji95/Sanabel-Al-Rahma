<?php

namespace App\Livewire;

use App\Models\Donor;
use App\Services\IdentityFormat;
use App\Services\MediaService;
use App\Payments\DuplicateTransactionRef;
use App\Payments\PaymentGateway;
use App\Services\TransferRouting;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * T-103 — giving without picking a family.
 * T-108 — and without signing in either.
 *
 * Asked for on 15 October («ما يحتاج يحط رقم ملف») and again on 17 October
 * («تبرع سريع … ما يحتاج يدخل ايميل وكلمة مرور، مباشرة ندخل على صفحة
 * التبرع»). Until now the only way to give was to pick a file, put it in a
 * basket, reserve it, sign in and then record the transfer — five things
 * between a donor and the one thing they came to do.
 *
 * Nothing new happens to the money. The domain has had two kinds of donation
 * since the beginning and admits no third: money earmarked for the files in
 * a basket, and general money the association spends at its discretion. This
 * is the second kind, and it reaches the same wallet as every other
 * donation, because a donor never pays a household directly.
 *
 * What an account was never doing:
 *
 *   The transfer happens at the donor's own bank and the system only records
 *   what they say they sent. The reference is what identifies it, and the
 *   finance desk matches that reference against the statement before a
 *   single figure moves (rule 5). So a signed-out record is a line in a
 *   queue awaiting verification, exactly like a signed-in one.
 *
 * What an account is still for: coming back to read your own history. A
 * guest therefore still gets a donor record — the association has somebody
 * to thank, and the same name giving twice is the same row — it simply has
 * no login attached to it.
 */
class GeneralDonation extends Component
{
    use WithFileUploads;

    public ?int $amount = null;

    public string $transactionRef = '';

    /** Asked of a guest only; a signed-in donor already has both on file. */
    public string $name = '';

    public string $phone = '';

    public ?string $notice = null;

    /** T-123 — an image or a PDF of the transfer, optional. */
    public $receipt = null;

    public ?string $error = null;

    /** Shown once, under the confirmation, in the association's own words. */
    public bool $thanks = false;

    public function record(): void
    {
        $this->error = $this->notice = null;
        $this->thanks = false;

        $signedIn = auth()->user()?->donor;

        $this->validate(array_filter([
            'amount' => ['required', 'integer', 'min:1'],
            'transactionRef' => ['required', 'string', 'max:191'],
            'name' => $signedIn ? null : ['required', 'string', 'min:3', 'max:191'],
            // T-121 — ten digits here too, when one is given at all.
            'phone' => $signedIn ? null : ['nullable', 'string', 'digits:'.IdentityFormat::PHONE_DIGITS],
            // T-123 — the slip for the transfer, image or PDF.
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:8192'],
        ]), attributes: [
            'amount' => __('sanabel.donation.amount'),
            'transactionRef' => __('sanabel.donation.transaction_ref'),
            'name' => __('sanabel.donation.donor'),
            'phone' => __('sanabel.donor.phone'),
            'receipt' => __('sanabel.donation.receipt_upload'),
        ]);

        /*
         | The page is open to anybody, so the one thing worth limiting is how
         | fast the queue can be filled. Not a security control — the
         | verification is — but a verifier reading a hundred invented
         | references is a verifier not reading the real ones.
         */
        if (RateLimiter::tooManyAttempts($key = 'donate:'.request()->ip(), 10)) {
            $this->error = __('sanabel.donation.too_many');

            return;
        }

        RateLimiter::hit($key, 3600);

        $donor = $signedIn ?: $this->guestDonor();

        try {
            // No basket, so the service marks it general on its own. Passing
            // the designation here as well would be a second place for the
            // same decision to be made differently.
            $donation = app(PaymentGateway::class)->record([
                'donor_id' => $donor->id,
                'amount' => $this->amount,
                'transaction_ref' => $this->transactionRef,
                'route' => 'platform',
            ]);
        } catch (DuplicateTransactionRef $e) {
            $this->error = $e->getMessage();

            return;
        }

        /*
         | T-123 — the slip, against the donation it belongs to. There may be
         | no account behind it: a guest's gift is still their own transfer,
         | and the row records what was uploaded without naming a person who
         | was never asked to sign in.
         */
        if ($this->receipt instanceof TemporaryUploadedFile) {
            try {
                $media = app(MediaService::class)
                    ->store($this->receipt, $donation, 'receipt', auth()->user());

                $donation->forceFill(['receipt_media_id' => $media->getKey()])->save();
            } catch (\RuntimeException $e) {
                // The money has already moved; the picture is not worth
                // losing the record of it over.
                $this->error = $e->getMessage();
            }
        }

        $this->amount = null;
        $this->transactionRef = '';
        $this->receipt = null;
        $this->notice = __('sanabel.public.transfer_recorded');
        $this->thanks = true;
    }

    /**
     * The donor record behind a signed-out gift.
     *
     * Matched on the telephone number where one is given, so a donor who
     * gives every month is one row rather than twelve. Without a number
     * there is nothing to match on and a new row is the honest answer: two
     * people are called أبو محمد.
     */
    private function guestDonor(): Donor
    {
        $phone = (string) IdentityFormat::digits($this->phone);

        if ($phone === '') {
            return Donor::create(['name_ar' => trim($this->name)]);
        }

        $hash = Donor::hashPhone($phone);

        return Donor::firstOrCreate(
            ['phone_hash' => $hash],
            ['name_ar' => trim($this->name), 'phone_encrypted' => $phone],
        );
    }

    public function render()
    {
        return view('livewire.general-donation', [
            'transferWallet' => app(TransferRouting::class)->wallet(),
            'signedIn' => (bool) auth()->user()?->donor,
        ])->layout('layouts.app');
    }
}
