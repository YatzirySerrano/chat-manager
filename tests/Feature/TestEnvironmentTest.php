<?php

test('server-side rendering is disabled so tests never depend on a running Vite server', function () {
    expect(config('inertia.ssr.enabled'))->toBeFalse();
});
