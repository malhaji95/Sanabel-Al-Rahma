<?php

use App\Filament\Pages\TwoFactor;
use App\Services\Totp;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;

/*
 | T-276 — found in the pre-launch security review of 8 November.
 |
 | Two things were missing from the second-factor screen.
 |
 | It had no limit of its own. It is reached through `/livewire/update`,
 | which allows three hundred a minute, and three of the million six-digit
 | codes are valid at any moment because the window is one step either
 | side — better than four hundred thousand tries a day against an account
 | whose password is already known.
 |
 | And a code was accepted for as long as it was valid: ninety seconds, in
 | which the same six digits worked again and again. RFC 6238 §5.2 says a
 | step is accepted once.
 */
beforeEach(function () {
    seedCore();

    $this->totp = app(Totp::class);
    $this->secret = $this->totp->generateSecret();

    $this->user = userWithRole('admin');
    $this->user->forceFill([
        'two_factor_secret' => $this->secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_last_counter' => null,
    ])->save();

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    RateLimiter::clear('two-factor:'.$this->user->getKey());
});

function enterCode(string $code)
{
    return Livewire\Livewire::actingAs(test()->user)
        ->test(TwoFactor::class)
        ->fillForm(['code' => $code])
        ->call('confirm');
}

it('accepts the right code and remembers which step it was', function () {
    enterCode($this->totp->at($this->secret));

    expect($this->user->refresh()->two_factor_last_counter)
        ->toBe(intdiv(time(), 30));
});

it('refuses the same code a second time, inside the window it stays valid', function () {
    $code = $this->totp->at($this->secret);

    enterCode($code);
    $first = $this->user->refresh()->two_factor_last_counter;

    // Same second, same code: still valid by the clock, and spent.
    enterCode($code);

    // Nothing moved, and the session was never opened by the second try.
    expect($this->user->refresh()->two_factor_last_counter)->toBe($first);
});

it('refuses a code from a step already behind us', function () {
    // Sign in with the current step, then offer the previous one, which the
    // ±1 window would otherwise still accept.
    enterCode($this->totp->at($this->secret));

    $used = $this->user->refresh()->two_factor_last_counter;
    $previous = $this->totp->at($this->secret, time() - 30);

    enterCode($previous);

    expect($this->user->refresh()->two_factor_last_counter)->toBe($used);
});

it('stops answering after five wrong codes', function () {
    $key = 'two-factor:'.$this->user->getKey();

    for ($i = 1; $i <= 5; $i++) {
        enterCode(str_pad((string) $i, 6, '0', STR_PAD_LEFT));
    }

    expect(RateLimiter::tooManyAttempts($key, 5))->toBeTrue();

    // The sixth is not even compared: the screen is closed for a while.
    enterCode('000006');

    expect(RateLimiter::attempts($key))->toBe(5);
});

it('refuses the right code too once the attempts are spent', function () {
    for ($i = 1; $i <= 5; $i++) {
        enterCode(str_pad((string) $i, 6, '0', STR_PAD_LEFT));
    }

    // Someone who guessed their way to the limit does not get in by
    // finally producing a good code; that is the whole point of the limit.
    enterCode($this->totp->at($this->secret));

    expect($this->user->refresh()->two_factor_last_counter)->toBeNull();
});

it('forgets the wrong attempts the moment a right code lands', function () {
    enterCode('000001');
    enterCode('000002');

    enterCode($this->totp->at($this->secret));

    expect(RateLimiter::attempts('two-factor:'.$this->user->getKey()))->toBe(0);
});

it('still compares in constant time, which is why the code is read this way', function () {
    // matchingCounter is what verify() answers from now; both must agree.
    $code = $this->totp->at($this->secret);

    expect($this->totp->verify($this->secret, $code))->toBeTrue()
        ->and($this->totp->matchingCounter($this->secret, $code))->toBe(intdiv(time(), 30))
        ->and($this->totp->matchingCounter($this->secret, '000000'))->toBeNull()
        ->and($this->totp->matchingCounter($this->secret, '12345'))->toBeNull();
});
