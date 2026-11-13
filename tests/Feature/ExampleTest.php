<?php

use App\Models\User;

test('the home page sends a guest to the sign-in page', function () {
    $this->get(route('home'))->assertRedirect('/login');
});

test('the home page sends a signed-in user to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('dashboard', absolute: false));
});
