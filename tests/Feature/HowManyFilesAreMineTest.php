<?php

use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Resources\BeneficiaryResource\Pages\ListBeneficiaries;
use Filament\Facades\Filament;
use Filament\Tables\Table;

/*
 | T-326 — «يظهر خيار عرض 25 ملفًا لكل صفحة, ولكن لا تظهر إمكانية الانتقال
 | إلى الصفحات التالية», a delegate, 25 November.
 |
 | The paging was never broken, and this file says so in its last test: the
 | sizes are 25, 50 and 100, and Filament draws the links when there is a
 | second page to draw them for. The delegate's own region held three files.
 |
 | What was missing is the number. Filament's «عرض ١ إلى ٣ من ٣» sits behind
 | `@container (min-width: 56rem)`, and the container is the table card inside
 | the panel — narrower than the window by the whole sidebar. On a tablet it
 | never reaches that, so the delegate saw a page-size box, no total, and no
 | way forward, and read it as files being kept from them.
 |
 | So the count is put in the page's subheading, which no breakpoint hides.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    passTwoFactor();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** The page as the delegate opens it. */
function theirList()
{
    test()->actingAs(test()->delegate);

    return Livewire\Livewire::test(ListBeneficiaries::class);
}

it('says how many files there are, in the delegate’s own words', function () {
    foreach (range(1, 3) as $ignored) {
        familyOf($this->region, adults: 1, children: 0);
    }

    expect(theirList()->instance()->getSubheading())->toBe('3 ملفات ضمن نطاقك');
});

it('counts the files of this region and not of another', function () {
    /*
     | The delegate's whole complaint was about files they could not reach.
     | A number that counted somebody else's region would answer it with a
     | lie, and send them hunting for files that were never theirs.
     */
    familyOf($this->region, adults: 1, children: 0);
    familyOf($this->region, adults: 2, children: 1);

    $elsewhere = regionWithRates();
    familyOf($elsewhere, adults: 1, children: 0);
    familyOf($elsewhere, adults: 1, children: 0);

    expect(theirList()->instance()->getSubheading())->toBe('ملفان ضمن نطاقك');
});

it('writes one, two and many the way Arabic writes them', function () {
    expect(trans_choice('sanabel.beneficiary.count_in_scope', 0, ['count' => 0]))
        ->toBe('لا توجد ملفات ضمن نطاقك')
        ->and(trans_choice('sanabel.beneficiary.count_in_scope', 1, ['count' => 1]))
        ->toBe('ملفٌ واحد ضمن نطاقك')
        ->and(trans_choice('sanabel.beneficiary.count_in_scope', 2, ['count' => 2]))
        ->toBe('ملفان ضمن نطاقك')
        ->and(trans_choice('sanabel.beneficiary.count_in_scope', 7, ['count' => 7]))
        ->toBe('7 ملفات ضمن نطاقك')
        ->and(trans_choice('sanabel.beneficiary.count_in_scope', 60, ['count' => 60]))
        ->toBe('60 ملفًا ضمن نطاقك');
});

it('says what a search matched, and out of how many', function () {
    $wanted = familyOf($this->region, adults: 1, children: 0);
    familyOf($this->region, adults: 1, children: 0);
    familyOf($this->region, adults: 1, children: 0);

    $page = theirList()->set('tableSearch', $wanted->file_number);

    expect($page->instance()->getSubheading())
        ->toBe('نتيجةٌ واحدة مطابقة, من أصل 3 ضمن نطاقك');
});

it('is on the screen itself, not only in the method', function () {
    familyOf($this->region, adults: 1, children: 0);

    expect(theirList()->html())->toContain('ملفٌ واحد ضمن نطاقك');
});

it('still offers 25, 50 and 100 to a page, and pages when there is a page to go to', function () {
    /*
     | The half of the complaint that was already true. Kept as a test so a
     | later edit cannot quietly take the sizes away, or the second page.
     */
    $table = BeneficiaryResource::table(
        Table::make(theirList()->instance())
    );

    expect($table->getPaginationPageOptions())->toBe([25, 50, 100]);

    foreach (range(1, 26) as $ignored) {
        familyOf($this->region, adults: 1, children: 0);
    }

    $page = theirList();

    expect($page->instance()->getTableRecords()->total())->toBe(26)
        ->and($page->instance()->getTableRecords()->hasMorePages())->toBeTrue()
        ->and($page->instance()->getTableRecords()->count())->toBe(25);
});
