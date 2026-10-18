<?php

test('the home page redirects to the dashboard', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('dashboard', absolute: false));
});
