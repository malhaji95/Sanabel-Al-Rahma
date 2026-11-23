<?php

namespace App\Services;

use App\Models\Donation;
use App\Models\DonationImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/*
 | T-322 — phase two, item 10: «استيراد ملفّات المحاسب».
 |
 | The accountant holds a bank statement and the platform holds a queue of
 | donors who say they transferred. Matching the two was done by eye, one
 | reference at a time, and a reference read wrong is money marked as arrived
 | that never did.
 |
 | The statement is read, every line is matched against the pending queue by
 | its transfer reference, and each line is given a verdict. Nothing is
 | believed yet: the file is staged, somebody reads the verdicts, and a second
 | hand approves. Only then does the matched half walk the verification path
 | that money has always walked.
 |
 | What this never does is create a donation. `donations.donor_id` is
 | required, a bank statement names no donor, and inventing a rule that ties a
 | statement line to a donor is not ours to invent.
 */
class AccountantStatement
{
    /**
     * The headers each field answers to, lowercased. Arabic because the
     * association's own files are Arabic, English because a bank's export
     * often is not.
     *
     * «مطابقة حقول» — the file says what it says, and the importer finds the
     * field rather than demanding a column order.
     *
     * @var array<string,array<int,string>>
     */
    public const HEADERS = [
        'transaction_ref' => ['transaction_ref', 'reference', 'ref', 'رقم العملية', 'المرجع', 'رقم الحوالة'],
        'amount' => ['amount', 'value', 'المبلغ', 'القيمة'],
        'currency' => ['currency', 'العملة'],
        'received_on' => ['date', 'received_on', 'التاريخ', 'تاريخ العملية'],
    ];

    /** A line the import will act on when it is approved. */
    public const READY = 'ready';

    public function __construct(private readonly DonationService $donations) {}

    /**
     * Reads the file, judges every line, and stages the result. Writes no
     * money: a staged import is a reading, not a decision.
     */
    public function stage(UploadedFile $file, User $uploader): DonationImport
    {
        $lines = $this->read($file);

        if ($lines === []) {
            throw new RuntimeException(__('sanabel.statement.empty'));
        }

        return DB::transaction(function () use ($file, $uploader, $lines) {
            $import = DonationImport::create([
                'original_name' => $file->getClientOriginalName(),
                'status' => 'staged',
                'uploaded_by' => $uploader->getKey(),
                'uploaded_at' => now(),
            ]);

            $seen = [];
            $tally = [];

            foreach ($lines as $index => $line) {
                $row = $this->judge($line, $seen);

                if ($row['transaction_ref']) {
                    $seen[$row['transaction_ref']] = true;
                }

                $tally[$row['outcome']] = ($tally[$row['outcome']] ?? 0) + 1;

                $import->rows()->create([
                    'row_no' => $index + 1,
                    'transaction_ref' => $row['transaction_ref'],
                    'amount' => $row['amount'],
                    'currency' => $row['currency'],
                    'received_on' => $row['received_on'],
                    'outcome' => $row['outcome'],
                    'donation_id' => $row['donation_id'],
                ]);
            }

            $import->forceFill(['summary_json' => $tally])->save();

            return $import->refresh();
        });
    }

    /**
     * Verifies the matched lines, and only those.
     *
     * The hand that approves is never the hand that uploaded, as everywhere
     * else money is touched. Each matched line walks `DonationService::verify`
     * — the one path to verified money, so allocation, the gift card, the
     * donor's badge and the notice all happen exactly as they always have.
     *
     * @return int how many donations were verified
     */
    public function approve(DonationImport $import, User $approver): int
    {
        if (! $import->isStaged()) {
            throw new RuntimeException(__('sanabel.statement.not_staged'));
        }

        if ((int) $import->uploaded_by === (int) $approver->getKey()) {
            throw new RuntimeException(__('sanabel.statement.approver_not_independent'));
        }

        return DB::transaction(function () use ($import, $approver) {
            $verified = 0;

            foreach ($import->rows()->where('outcome', self::READY)->get() as $row) {
                $donation = Donation::find($row->donation_id);

                // Read again at the moment of acting: a donation verified by
                // hand between the staging and the approval is no longer this
                // import's to verify, and `verify` would refuse it anyway.
                if (! $donation || $donation->status !== 'pending') {
                    $row->forceFill(['outcome' => 'already_verified'])->save();

                    continue;
                }

                $this->donations->verify($donation, $approver->getKey());
                $verified++;
            }

            $import->forceFill([
                'status' => 'approved',
                'approved_by' => $approver->getKey(),
                'approved_at' => now(),
            ])->save();

            return $verified;
        });
    }

    /** Set aside, with the reason on the row. Nothing is deleted. */
    public function discard(DonationImport $import, User $actor, string $reason): DonationImport
    {
        if (! $import->isStaged()) {
            throw new RuntimeException(__('sanabel.statement.not_staged'));
        }

        if (trim($reason) === '') {
            throw new RuntimeException(__('sanabel.statement.discard_reason_required'));
        }

        $import->forceFill([
            'status' => 'discarded',
            'discard_reason_ar' => trim($reason),
            'approved_by' => $actor->getKey(),
            'approved_at' => now(),
        ])->save();

        return $import->refresh();
    }

    /**
     * What one line of the statement means.
     *
     * @param  array<string,string>  $line
     * @param  array<string,bool>  $seen  references already met in this file
     * @return array<string,mixed>
     */
    private function judge(array $line, array $seen): array
    {
        $ref = trim((string) ($line['transaction_ref'] ?? ''));
        $amount = $this->toInteger($line['amount'] ?? null);
        $currency = trim((string) ($line['currency'] ?? '')) ?: null;
        $receivedOn = $this->toDate($line['received_on'] ?? null);

        $row = [
            'transaction_ref' => $ref ?: null,
            'amount' => $amount,
            'currency' => $currency,
            'received_on' => $receivedOn,
            'donation_id' => null,
        ];

        // «الناقص» — a line with no reference or no amount cannot be matched
        // to anything, and guessing which donation it meant is the one thing
        // a reconciliation must never do.
        if ($ref === '' || $amount === null) {
            return $row + ['outcome' => 'incomplete'];
        }

        // «المكرَّر» — twice in the same file. The second is not a second
        // transfer; it is the same line read twice.
        if (isset($seen[$ref])) {
            return $row + ['outcome' => 'repeated_in_file'];
        }

        $donation = Donation::withoutGlobalScopes()->where('transaction_ref', $ref)->first();

        if (! $donation) {
            return $row + ['outcome' => 'no_match'];
        }

        $row['donation_id'] = $donation->getKey();

        if ($donation->status !== 'pending') {
            return $row + ['outcome' => 'already_verified'];
        }

        /*
         | The amount is the second half of a match. A reference that agrees
         | and a figure that does not is the case worth a human: either the
         | donor typed the wrong reference, or the transfer was not the
         | transfer they said it was.
         */
        if ((int) $donation->amount !== $amount) {
            return $row + ['outcome' => 'amount_mismatch'];
        }

        return $row + ['outcome' => self::READY];
    }

    /**
     * The file, as rows keyed by the fields its headers name.
     *
     * @return array<int,array<string,string>>
     */
    private function read(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw new RuntimeException(__('sanabel.statement.unreadable'));
        }

        $header = fgetcsv($handle);

        if ($header === false || $header === null) {
            fclose($handle);

            throw new RuntimeException(__('sanabel.statement.empty'));
        }

        $map = $this->mapHeaders($header);

        if (! isset($map['transaction_ref'], $map['amount'])) {
            fclose($handle);

            throw new RuntimeException(__('sanabel.statement.missing_columns'));
        }

        $lines = [];

        while (($values = fgetcsv($handle)) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }

            $line = [];

            foreach ($map as $field => $position) {
                $line[$field] = (string) ($values[$position] ?? '');
            }

            // A row with nothing in any of the columns we read is a blank
            // line at the end of a spreadsheet, not a transfer.
            if (trim(implode('', $line)) === '') {
                continue;
            }

            $lines[] = $line;
        }

        fclose($handle);

        return $lines;
    }

    /**
     * @param  array<int,string|null>  $header
     * @return array<string,int>
     */
    private function mapHeaders(array $header): array
    {
        $map = [];

        foreach ($header as $position => $name) {
            // A BOM on the first cell is what Excel writes, and it makes the
            // first column match nothing at all.
            $name = mb_strtolower(trim(preg_replace('/^\x{FEFF}/u', '', (string) $name)));

            foreach (self::HEADERS as $field => $names) {
                if (! isset($map[$field]) && in_array($name, $names, true)) {
                    $map[$field] = $position;
                }
            }
        }

        return $map;
    }

    /**
     * Money is an integer in the smallest unit (rule 9).
     *
     * A bank writes a figure with separators and with cents: «1,250,000.00».
     * Stripping everything that is not a digit reads that as a hundred and
     * twenty-five million, which is a hundredfold error in the direction
     * nobody notices until it is allocated. So the separators go, and the
     * figure is cut at the decimal point rather than swallowed past it.
     */
    private function toInteger(?string $value): ?int
    {
        $value = trim((string) $value);

        // Thousands separators, Arabic ones included, and any currency word
        // the bank put in the cell.
        $value = str_replace([',', '٬', ' ', "\u{00A0}", '٫'], ['', '', '', '', '.'], $value);
        $value = preg_replace('/[^\d.-]/', '', $value);

        // Everything from the decimal point on is a fraction of a unit the
        // system does not keep, and dropping it is nearer the truth than
        // multiplying by it.
        $value = explode('.', (string) $value)[0];

        return $value === '' || $value === '-' ? null : (int) $value;
    }

    private function toDate(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            // An unreadable date is not a reason to refuse the line: the
            // reference and the amount are what match it.
            return null;
        }
    }
}
