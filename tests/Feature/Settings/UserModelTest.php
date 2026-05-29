<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts must_change_password and defaults to false', function () {
    $u = User::factory()->create();
    expect($u->must_change_password)->toBeFalse();
    $u->update(['must_change_password' => true]);
    expect($u->fresh()->must_change_password)->toBeTrue();
});
