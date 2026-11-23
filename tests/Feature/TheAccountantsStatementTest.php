<?php

use App\Filament\Pages\AccountantStatements;
use App\Models\Donation;
use App\Models\DonationImport;
use App\Models\Donor;
use App\Services\AccountantStatement;
use App\Services\DonationService;
use Illuminate\Http\UploadedFile;

/*
 | T-322 — phase two, item 10: «استيراد ملفّات المحاسب».
 |
 | The accountant holds a bank statement and the platform holds a queue of
 | donors who say they transferred. Matching the two was done by eye, one
 | reference at a time, and a reference read wrong is money marked as arrived
 | that never did.
 |
 | The statement is read, matched, and given verdicts; nothing is believed
 | until a second hand approves. And nothing here creates a donation:
 | `donations.donor_id` is required, a bank statement names no donor, and
 | inventing a rule that ties a line to a donor is not ours to invent.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->accountant = userWithRole('finance');
    /*
     | T-322 — `verify_payment` is held by two roles only, the accountant and
     | the treasurer, and this screen's one button verifies money. So the hand
     | that approves a statement is the other of the two: two hands that may
     | both verify, and never one of them being both.
     */
    $this->treasurer = userWithRole('treasurer');
    $this->director = userWithRole('executive_director');
    $this->statements = app(AccountantStatement::class);
});

/** A donor who says they transferred, waiting to be believed. */
function pendingDonation(string $ref, int $amount = 100000): Donation
{
    return app(DonationService::class)->record([
        'donor_id' => Donor::factory()->create()->id,
        'amount' => $amount,
        'currency' => config('sanabel.currency'),
        'transaction_ref' => $ref,
    ]);
}

/** A statement as the bank exports one. */
function statementFile(array $lines, array $header = ['رقم العملية', 'المبلغ', 'العملة', 'التاريخ']): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'stmt').'.csv';
    $handle = fopen($path, 'w');

    fputcsv($handle, $header);

    foreach ($lines as $line) {
        fputcsv($handle, $line);
    }

    fclose($handle);

    return new UploadedFile($path, 'statement.csv', 'text/csv', null, true);
}

it('reads the file and gives every line its verdict, without touching money', function () {
    $matched = pendingDonation('TRX-001', 100000);
    $wrongAmount = pendingDonation('TRX-002', 250000);
    $alreadyDone = pendingDonation('TRX-003', 50000);
    app(DonationService::class)->verify($alreadyDone, $this->director->id);

    $import = $this->statements->stage(statementFile([
        ['TRX-001', '100000', 'SYP', '2026-11-01'],
        ['TRX-002', '999999', 'SYP', '2026-11-02'],
        ['TRX-003', '50000', 'SYP', '2026-11-03'],
        ['TRX-404', '70000', 'SYP', '2026-11-04'],
        ['TRX-001', '100000', 'SYP', '2026-11-05'],
        ['', '80000', 'SYP', '2026-11-06'],
    ]), $this->accountant);

    $byRow = $import->rows()->orderBy('row_no')->pluck('outcome', 'row_no')->all();

    expect($byRow)->toBe([
        1 => 'ready',
        2 => 'amount_mismatch',
        3 => 'already_verified',
        4 => 'no_match',
        5 => 'repeated_in_file',
        6 => 'incomplete',
    ]);

    // Staging is a reading, not a decision: the queue is untouched.
    expect($matched->refresh()->status)->toBe('pending')
        ->and($wrongAmount->refresh()->status)->toBe('pending')
        ->and($import->status)->toBe('staged')
        ->and($import->summary_json['ready'])->toBe(1)
        ->and($import->uploaded_by)->toBe($this->accountant->id);
});

it('verifies the matched half and nothing else, when a second hand approves', function () {
    $matched = pendingDonation('TRX-010', 100000);
    $mismatch = pendingDonation('TRX-011', 250000);

    $import = $this->statements->stage(statementFile([
        ['TRX-010', '100000', 'SYP', '2026-11-01'],
        ['TRX-011', '1', 'SYP', '2026-11-02'],
        ['TRX-999', '9000', 'SYP', '2026-11-03'],
    ]), $this->accountant);

    expect($this->statements->approve($import, $this->director))->toBe(1);

    expect($matched->refresh()->status)->toBe('verified')
        ->and($matched->verified_by)->toBe($this->director->id)
        // The one that did not agree is left for a human, not quietly passed.
        ->and($mismatch->refresh()->status)->toBe('pending');

    $import->refresh();

    expect($import->status)->toBe('approved')
        ->and($import->approved_by)->toBe($this->director->id);
});

it('refuses the approval of the hand that uploaded it', function () {
    /*
     | A hand that both sends the statement and believes it is the whole of
     | the control, which is the rule everywhere else money is touched.
     */
    pendingDonation('TRX-020');

    $import = $this->statements->stage(statementFile([
        ['TRX-020', '100000', 'SYP', '2026-11-01'],
    ]), $this->accountant);

    expect(fn () => $this->statements->approve($import, $this->accountant))
        ->toThrow(RuntimeException::class, __('sanabel.statement.approver_not_independent'));

    expect($import->refresh()->status)->toBe('staged')
        ->and(Donation::where('transaction_ref', 'TRX-020')->value('status'))->toBe('pending');
})->group('AUTH-01');

it('refuses to approve the same statement twice', function () {
    pendingDonation('TRX-030');

    $import = $this->statements->stage(statementFile([
        ['TRX-030', '100000', 'SYP', '2026-11-01'],
    ]), $this->accountant);

    $this->statements->approve($import, $this->director);

    expect(fn () => $this->statements->approve($import->refresh(), $this->director))
        ->toThrow(RuntimeException::class, __('sanabel.statement.not_staged'));
});

it('notices a donation verified by hand between the reading and the approval', function () {
    /*
     | The verdict was reached minutes ago and the world moved. Acting on a
     | stale reading is how money gets verified twice, so the row is read
     | again at the moment of acting.
     */
    $donation = pendingDonation('TRX-040', 100000);

    $import = $this->statements->stage(statementFile([
        ['TRX-040', '100000', 'SYP', '2026-11-01'],
    ]), $this->accountant);

    app(DonationService::class)->verify($donation, $this->director->id);

    expect($this->statements->approve($import, $this->director))->toBe(0);

    expect($import->rows()->first()->refresh()->outcome)->toBe('already_verified');
});

it('finds its columns by their names, in Arabic or in English, in any order', function () {
    pendingDonation('TRX-050', 100000);

    $import = $this->statements->stage(statementFile(
        [['SYP', '100000', 'TRX-050']],
        ['Currency', 'Amount', 'Reference'],
    ), $this->accountant);

    expect($import->rows()->first()->outcome)->toBe('ready')
        ->and($import->rows()->first()->currency)->toBe('SYP');
});

it('refuses a file with no reference column or no amount column', function () {
    expect(fn () => $this->statements->stage(
        statementFile([['2026-11-01', 'SYP']], ['التاريخ', 'العملة']),
        $this->accountant,
    ))->toThrow(RuntimeException::class, __('sanabel.statement.missing_columns'));

    expect(DonationImport::count())->toBe(0);
});

it('refuses a file with nothing in it', function () {
    expect(fn () => $this->statements->stage(statementFile([]), $this->accountant))
        ->toThrow(RuntimeException::class, __('sanabel.statement.empty'));
});

it('reads a figure written the way a bank writes it', function () {
    pendingDonation('TRX-060', 1250000);

    $import = $this->statements->stage(statementFile([
        ['TRX-060', '1,250,000.00', 'SYP', '2026-11-01'],
    ]), $this->accountant);

    /*
     | Stripping everything that is not a digit read «1,250,000.00» as a
     | hundred and twenty-five million: a hundredfold error in the direction
     | nobody notices until the money is allocated. The separators go and the
     | figure is cut at the decimal point.
     */
    expect($import->rows()->first()->amount)->toBe(1250000)
        ->and($import->rows()->first()->outcome)->toBe('ready');
});

it('reads a figure written with Arabic separators', function () {
    pendingDonation('TRX-061', 980000);

    $import = $this->statements->stage(statementFile([
        ['TRX-061', '٩٨٠٬٠٠٠٫٠٠ ل.س', 'SYP', '2026-11-01'],
    ]), $this->accountant);

    // The Arabic-Indic digits themselves are not translated here: a file that
    // writes its figures in them is reported as incomplete rather than
    // guessed at, which is the safe answer until the association sends one.
    expect($import->rows()->first()->outcome)->toBe('incomplete');
});

it('sets a statement aside with the reason on it, and verifies nothing', function () {
    pendingDonation('TRX-070');

    $import = $this->statements->stage(statementFile([
        ['TRX-070', '100000', 'SYP', '2026-11-01'],
    ]), $this->accountant);

    $this->statements->discard($import, $this->director, 'الكشف من شهر خطأ.');

    $import->refresh();

    expect($import->status)->toBe('discarded')
        ->and($import->discard_reason_ar)->toBe('الكشف من شهر خطأ.')
        ->and($import->deleted_at)->toBeNull()
        ->and(Donation::where('transaction_ref', 'TRX-070')->value('status'))->toBe('pending');

    expect(fn () => $this->statements->discard($import, $this->director, 'مرة ثانية.'))
        ->toThrow(RuntimeException::class, __('sanabel.statement.not_staged'));
});

it('never creates a donation out of a statement line', function () {
    /*
     | `donations.donor_id` is required and a bank statement names no donor.
     | A line with no donation behind it is reported and left alone; guessing
     | whose money it was is the one thing a reconciliation must not do.
     */
    $before = Donation::count();

    $import = $this->statements->stage(statementFile([
        ['TRX-080', '100000', 'SYP', '2026-11-01'],
        ['TRX-081', '200000', 'SYP', '2026-11-02'],
    ]), $this->accountant);

    $this->statements->approve($import, $this->director);

    expect(Donation::count())->toBe($before)
        ->and($import->rows()->pluck('outcome')->all())->toBe(['no_match', 'no_match']);
});

it('opens the screen to the hand that verifies money, and to no other', function () {
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    passTwoFactor();

    $this->actingAs($this->treasurer);
    expect(AccountantStatements::canAccess())->toBeTrue();

    $this->actingAs($this->accountant);
    expect(AccountantStatements::canAccess())->toBeTrue();

    // The executive director approves a payment order and verifies no
    // transfer: the two are different hands and this is the verifier's screen.
    $this->actingAs($this->director);
    expect(AccountantStatements::canAccess())->toBeFalse();

    // A delegate writes no money and reads no statement.
    $this->actingAs(userWithRole('delegate', ['region_id' => $this->region->id]));
    expect(AccountantStatements::canAccess())->toBeFalse();

    // Nor does oversight, which reads everything and writes nothing: this
    // screen's one button verifies money.
    $this->actingAs(userWithRole('oversight_director'));
    expect(AccountantStatements::canAccess())->toBeFalse();
})->group('AUTH-01');

it('draws every line and its verdict before anything is approved', function () {
    pendingDonation('TRX-090', 100000);

    $import = $this->statements->stage(statementFile([
        ['TRX-090', '100000', 'SYP', '2026-11-01'],
        ['TRX-091', '5000', 'SYP', '2026-11-02'],
    ]), $this->accountant);

    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    passTwoFactor();
    $this->actingAs($this->treasurer);

    $html = Livewire\Livewire::test(AccountantStatements::class)->html();

    expect($html)->toContain('TRX-090')
        ->toContain('TRX-091')
        ->toContain(__('sanabel.statement.outcomes.ready'))
        ->toContain(__('sanabel.statement.outcomes.no_match'))
        ->toContain($this->accountant->name)
        ->toContain(__('sanabel.statement.approve'));

    expect($import->refresh()->status)->toBe('staged');
});

it('verifies from the screen, and refuses the uploader pressing it', function () {
    $donation = pendingDonation('TRX-100', 100000);

    $import = $this->statements->stage(statementFile([
        ['TRX-100', '100000', 'SYP', '2026-11-01'],
    ]), $this->accountant);

    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    passTwoFactor();

    // The accountant who sent it presses approve: refused, and nothing moves.
    $this->actingAs($this->accountant);
    Livewire\Livewire::test(AccountantStatements::class)->call('approve', $import->id);

    expect($donation->refresh()->status)->toBe('pending')
        ->and($import->refresh()->status)->toBe('staged');

    // The treasurer presses it: the money is verified.
    $this->actingAs($this->treasurer);
    Livewire\Livewire::test(AccountantStatements::class)->call('approve', $import->id);

    expect($donation->refresh()->status)->toBe('verified')
        ->and($import->refresh()->status)->toBe('approved');
})->group('AUTH-01');
