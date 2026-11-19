<?php

use App\Services\Auth\Totp;

it('matches RFC TOTP test vectors and rejects reuse of the same counter', function () {
    $totp = new Totp;
    $secret = '12345678901234567890';

    expect($totp->encodeSecret($secret))->toBe('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ')
        ->and($totp->code($secret, intdiv(59, 30)))->toBe('287082')
        ->and($totp->code($secret, intdiv(1_111_111_109, 30)))->toBe('081804')
        ->and($totp->matchStep($secret, '287082', 59, null))->toBe(1)
        ->and($totp->matchStep($secret, '287082', 59, 1))->toBeNull();
});
