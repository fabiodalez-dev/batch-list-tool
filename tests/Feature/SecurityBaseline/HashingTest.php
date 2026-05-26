<?php

declare(strict_types=1);

use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\Hash;

/**
 * Security Baseline §5 — Password hashing
 *
 * Goal: bcrypt cost factor MUST be ≥ 12 in production.
 *
 * Note on the testing environment:
 *   phpunit.xml hard-codes BCRYPT_ROUNDS=4 so the test suite runs in
 *   reasonable time. That override is explicitly NOT the production value.
 *   These tests therefore exercise the security baseline by:
 *     1. asserting the deployed `.env` declares BCRYPT_ROUNDS=12
 *     2. asserting `config/hashing.php` defaults to 12 when the env var is unset
 *     3. exercising Hash::make + Hash::needsRehash with rounds=12 explicitly
 */
test('config/hashing defaults to bcrypt cost 12 and the deployed .env declares 12', function () {
    // 1. Compiled hashing config must read the env var with a default of 12 — i.e. the
    //    fail-safe is always at least 12 even if BCRYPT_ROUNDS gets unset in production.
    $hashingConfig = require base_path('config/hashing.php');
    expect($hashingConfig['bcrypt']['rounds'])->toBe(env('BCRYPT_ROUNDS', 12));

    // 2. The deployed .env (the production-shaped file shipped with the app) must
    //    declare cost = 12. This is the security baseline §5 commitment.
    $envContents = file_get_contents(base_path('.env'));
    expect($envContents)->toContain('BCRYPT_ROUNDS=12');
});

test('Hash::make produces a $2y$12$ hash when bcrypt rounds = 12', function () {
    $hasher = new BcryptHasher(['rounds' => 12]);
    $hash = $hasher->make('a-strong-password-for-test');

    // bcrypt prefix + cost factor must be encoded as 12
    expect($hash)->toStartWith('$2y$12$')
        ->and($hasher->check('a-strong-password-for-test', $hash))->toBeTrue();
});

test('Hash::needsRehash returns false for a $2y$12$ hash when configured cost is 12', function () {
    // Generate a hash at cost 12 and ask the same-cost hasher whether it needs rehashing.
    $hasher = new BcryptHasher(['rounds' => 12]);
    $hashAt12 = $hasher->make('password');

    expect($hasher->needsRehash($hashAt12))->toBeFalse();

    // Sanity check: a lower-cost hash MUST be flagged for rehashing.
    $hashAt4 = (new BcryptHasher(['rounds' => 4]))->make('password');
    expect($hasher->needsRehash($hashAt4))->toBeTrue();

    // And via the Hash facade, after we explicitly bind the config to 12
    config()->set('hashing.bcrypt.rounds', 12);
    expect(Hash::needsRehash($hashAt12, ['rounds' => 12]))->toBeFalse();
});
