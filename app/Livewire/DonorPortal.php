<?php

namespace App\Livewire;

use App\Exceptions\DuplicateTransactionRef;
use App\Http\Resources\MaskedCaseResource;
use App\Models\Donation;
use App\Payments\PaymentGateway;
use App\Services\MediaService;
use App\Services\TransferRouting;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * T-26 — my donations, my badge, and a general donation.
 *
 * A general donation names no family: it reaches the association's wallet and
 * the association decides where it goes. The earmarked kind goes through the
 * basket instead, on its own screen.
 */
class DonorPortal extends Component
{
    public ?int $generalAmount = null;

    public string $generalRef = '';

    public ?string $error = null;

    public ?string $notice = null;

    public function recordGeneral(): void
    {
        $this->error = $this->notice = null;

        $this->validate([
            'generalAmount' => ['required', 'integer', 'min:1'],
            'generalRef' => ['required', 'string', 'max:191'],
        ], attributes: [
            'generalAmount' => __('sanabel.public.amount'),
            'generalRef' => __('sanabel.donation.transaction_ref'),
        ]);

        $donor = auth()->user()->donor;

        if (! $donor) {
            $this->error = __('sanabel.public.donor_account_required');

            return;
        }

        try {
            app(PaymentGateway::class)->record([
                'donor_id' => $donor->id,
                'amount' => (int) $this->generalAmount,
                'transaction_ref' => $this->generalRef,
                'designation' => 'general',
            ]);
        } catch (DuplicateTransactionRef $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->generalAmount = null;
        $this->generalRef = '';
        $this->notice = __('sanabel.public.transfer_recorded');
    }

    /**
     * T-204 — the file numbers behind one transfer, confirmed or not.
     *
     * Masked either way: `MaskedCaseResource` is the only thing a donor is
     * ever served a family through (rule 2), and a file number is all of a
     * family that appears here.
     *
     * @return Collection<int,string>
     */
    private function fileNumbersOn(Donation $donation): Collection
    {
        $families = $donation->allocations
            ->map(fn ($allocation) => $allocation->beneficiary)
            ->filter();

        if ($families->isEmpty()) {
            $families = $donation->basket?->items
                ->map(fn ($item) => $item->beneficiary)
                ->filter() ?? collect();
        }

        return $families
            ->map(fn ($family) => (new MaskedCaseResource($family))->resolve()['file_number'])
            ->unique()
            ->values();
    }

    public function render()
    {
        $donor = auth()->user()->donor;

        $donations = $donor
            ? $donor->donations()
                ->with([
                    'allocations.beneficiary',
                    // T-204 — the basket the transfer came from, so a gift
                    // still waiting on the treasurer can name its families.
                    'basket.items.beneficiary',
                    'transferRecord',
                    'receipt',
                ])
                ->latest('id')->take(50)->get()
            : collect();

        return view('livewire.donor-portal', [
            'donor' => $donor,
            // The same wallet the basket sends earmarked money to.
            'generalRoute' => app(TransferRouting::class)->wallet(),
            'donations' => $donations->map(fn (Donation $donation) => [
                // T-203 — the platform's number, beside the bank's.
                'reference_no' => $donation->reference_no,
                'transaction_ref' => $donation->transaction_ref,
                'amount' => $donation->amount,
                'status' => $donation->status,
                'status_label' => __('sanabel.donations.'.$donation->status),
                'designation' => $donation->designation,
                // A short-lived signed link, so the file itself stays private.
                // A record still waiting on its checks (T-58) simply does not
                // appear: the donor's own page is not the place to explain it.
                'transfer_record' => $donation->transferRecord?->isServable()
                    ? app(MediaService::class)->temporaryUrl($donation->transferRecord, 15)
                    : null,
                'designation_label' => __('sanabel.designation.'.$donation->designation),
                /*
                 | T-181 — «نعم نحتاج إضافة غير المؤكدة ومعها تاريخ والمبلغ
                 | والحالة, ويعرض سبب الرفض والإيصال», 29 October.
                 |
                 | The waiting row was already here with its date, its amount
                 | and its status; these two were not. A donor told «مرفوضة»
                 | and nothing else has no idea whether to send the money
                 | again or to send a clearer photograph of the slip.
                 |
                 | The reason is shown only on a refusal: on any other row it
                 | is either empty or stale, and a reason beside a verified
                 | transfer reads like a complaint about money that arrived.
                 */
                'reject_reason' => $donation->status === 'rejected' ? $donation->reject_reason : null,
                // Their own slip, behind the same short-lived signed link the
                // association's own paper uses: the file itself stays private.
                'receipt' => $donation->receipt?->isServable()
                    ? app(MediaService::class)->temporaryUrl($donation->receipt, 15)
                    : null,
                'created_at' => $donation->created_at->translatedFormat('Y-m-d'),
                /*
                 | T-204 — «المفروض رقم ملف الفقير يكون ظاهر عنده, لو بدون
                 | تأكيد الطلب; إذا ما كان موجود رح يسبب لنا مشكلة»,
                 | 1 November, over a row reading «مخصص لملفات محددة» with a
                 | dash where the file numbers belong.
                 |
                 | The dash was right and useless. Allocations are written
                 | when the treasurer matches the transfer, so between the
                 | donor sending the money and the desk confirming it the
                 | row said «earmarked» and named nothing — and a donor who
                 | cannot see which family they paid for cannot check that
                 | the association matched it to the right one.
                 |
                 | The basket is where the donor chose those families, and it
                 | is theirs. So: the allocations once they exist, and the
                 | basket until they do.
                 */
                'cases' => $this->fileNumbersOn($donation),
            ]),
        ])->layout('layouts.app');
    }
}
