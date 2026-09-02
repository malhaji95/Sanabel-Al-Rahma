<?php

use App\Livewire\AddToBasket;
use App\Livewire\BrowseCases;
use App\Livewire\DonorBasket;
use App\Livewire\DonorPortal;
use App\Models\Banner;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\DonationAllocation;
use App\Models\Donor;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Services\CoverageService;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

function donorFor(): User
{
    $user = userWithRole('donor');
    Donor::factory()->create(['user_id' => $user->id]);

    return $user;
}

it('renders the public home page with CMS content', function () {
    Banner::create(['title_ar' => 'بانر ترحيبي', 'is_published' => true, 'sort_order' => 1]);
    Post::create(['slug' => 'khabar', 'title_ar' => 'خبر منشور', 'body_ar' => 'نص', 'is_published' => true, 'published_at' => now()]);
    Campaign::factory()->create([
        'title_ar' => 'حملة الشتاء',
        'surplus_policy_text_ar' => 'يوجه الفائض لحملة مماثلة.',
        'is_published' => true,
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('بانر ترحيبي')
        ->assertSee('خبر منشور')
        ->assertSee('حملة الشتاء')
        // Right-to-left Arabic on every page.
        ->assertSee('dir="rtl"', escape: false)
        ->assertSee('lang="ar"', escape: false);
});

it('renders news, a post, a CMS page and the campaigns list', function () {
    Post::create(['slug' => 'story', 'title_ar' => 'قصة', 'body_ar' => 'التفاصيل', 'is_published' => true, 'published_at' => now()]);
    Page::create(['slug' => 'about', 'title_ar' => 'من نحن', 'body_ar' => 'نبذة', 'is_published' => true]);
    Campaign::factory()->create(['surplus_policy_text_ar' => 'سياسة', 'is_published' => true]);

    $this->get(route('news'))->assertOk()->assertSee('قصة');
    $this->get(route('post', 'story'))->assertOk()->assertSee('التفاصيل');
    $this->get(route('page', 'about'))->assertOk()->assertSee('نبذة');
    $this->get(route('campaigns.public'))->assertOk();

    // An unpublished page is not reachable.
    Page::create(['slug' => 'draft', 'title_ar' => 'مسودة', 'is_published' => false]);
    $this->get(route('page', 'draft'))->assertNotFound();
});

it('browses cases without leaking anything identifying', function () {
    $case = publishedCase($this->region);

    $response = Livewire::test(BrowseCases::class)
        ->assertSee($case->file_number)
        ->assertDontSee($case->first_name)
        ->assertDontSee($case->family_name)
        ->assertDontSee((string) $case->national_id_encrypted);

    $response->assertSuccessful();
});

it('shows monthly and one-time cases as two separate lists', function () {
    $monthly = publishedCase($this->region, attributes: ['support_type' => 'monthly']);
    $oneTime = publishedCase($this->region, attributes: ['support_type' => 'one_time']);

    Livewire::test(BrowseCases::class)
        ->assertSee($monthly->file_number)
        ->assertDontSee($oneTime->file_number)
        ->set('supportType', 'one_time')
        ->assertSee($oneTime->file_number)
        ->assertDontSee($monthly->file_number);
});

it('adds a family to the basket, reserves it and records the transfer', function () {
    $case = publishedCase($this->region);
    $user = donorFor();
    $remaining = app(CoverageService::class)->remainingNeed($case);

    Livewire::actingAs($user)
        ->test(AddToBasket::class, [
            'fileNumber' => $case->file_number,
            'remaining' => $remaining,
        ])
        ->set('amount', 5_000)
        ->call('add')
        ->assertSet('notice', __('sanabel.public.added_to_basket'));

    Livewire::actingAs($user)
        ->test(DonorBasket::class)
        ->assertSee($case->file_number)
        ->call('reserve')
        ->assertSet('error', null)
        ->set('transactionRef', 'TRX-PORTAL-1')
        ->call('recordTransfer')
        ->assertSet('notice', __('sanabel.public.transfer_recorded'));

    expect(Donation::where('transaction_ref', 'TRX-PORTAL-1')->exists())->toBeTrue();
});

it('refuses a basket amount beyond the remaining need', function () {
    $case = publishedCase($this->region);
    $user = donorFor();
    $remaining = app(CoverageService::class)->remainingNeed($case);

    Livewire::actingAs($user)
        ->test(AddToBasket::class, [
            'fileNumber' => $case->file_number,
            'remaining' => $remaining,
        ])
        ->set('amount', $remaining + 1)
        ->call('add')
        ->assertHasErrors('amount');
});

it('surfaces a duplicate transaction ref to the donor as a review message', function () {
    $case = publishedCase($this->region);
    $user = donorFor();

    Donation::factory()->create([
        'donor_id' => $user->donor->id,
        'transaction_ref' => 'TRX-DUP-PORTAL',
    ]);

    Livewire::actingAs($user)
        ->test(AddToBasket::class, [
            'fileNumber' => $case->file_number,
            'remaining' => 10_000,
        ])
        ->set('amount', 1_000)
        ->call('add');

    Livewire::actingAs($user)
        ->test(DonorBasket::class)
        ->set('transactionRef', 'TRX-DUP-PORTAL')
        ->call('recordTransfer')
        ->assertSet('error', __('sanabel.donations.duplicate_ref').' (TRX-DUP-PORTAL)');
});

it('shows the donor their own donations and badge, masked', function () {
    $case = publishedCase($this->region);
    $user = donorFor();

    $donation = Donation::factory()->create(['donor_id' => $user->donor->id]);
    DonationAllocation::create([
        'donation_id' => $donation->id,
        'beneficiary_id' => $case->id,
        'amount' => 1_000,
        'currency' => 'SYP',
    ]);

    Livewire::actingAs($user)
        ->test(DonorPortal::class)
        ->assertSee($donation->transaction_ref)
        ->assertSee($case->file_number)
        ->assertDontSee($case->family_name);
});

it('serves the delegate field app to a delegate and refuses everyone else', function () {
    $case = publishedCase($this->region);
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);

    $this->actingAs($delegate)
        ->get(route('field'))
        ->assertOk()
        ->assertSee($case->file_number)
        // The device needs the case's server version to detect a conflict on sync.
        ->assertSee('data-updated', escape: false)
        ->assertSee('manifest', escape: false);

    $this->actingAs(donorFor())->get(route('field'))->assertForbidden();
    $this->actingAs(userWithRole('council'))->get(route('field'))->assertForbidden();
});

it('serves the field manifest and service worker', function () {
    $this->get(route('field.manifest'))->assertOk();

    expect(file_exists(public_path('field-sw.js')))->toBeTrue()
        ->and(file_get_contents(public_path('field-sw.js')))->toContain('sanabel-field');
});

it('logs a user in and sends them to the right screen for their role', function () {
    $donor = donorFor();
    $donor->forceFill(['password' => Hash::make('secret123')])->save();

    $this->post(route('login'), ['email' => $donor->email, 'password' => 'secret123'])
        ->assertRedirect(route('donor.portal'));

    $this->post(route('logout'))->assertRedirect(route('home'));

    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $delegate->forceFill(['password' => Hash::make('secret123')])->save();

    $this->post(route('login'), ['email' => $delegate->email, 'password' => 'secret123'])
        ->assertRedirect(route('field'));
});

it('refuses to log in a deactivated account', function () {
    $user = donorFor();
    $user->forceFill([
        'password' => Hash::make('secret123'),
        'is_active' => false,
    ])->save();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'secret123'])
        ->assertSessionHasErrors('email');

    expect(auth()->check())->toBeFalse();
});
