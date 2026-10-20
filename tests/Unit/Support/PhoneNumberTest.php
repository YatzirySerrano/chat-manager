<?php

use App\Support\PhoneNumber;

test('phone numbers are normalized to E.164', function (string $input, string $expected) {
    expect(PhoneNumber::normalize($input))->toBe($expected);
})->with([
    'already E.164' => ['+525512345678', '+525512345678'],
    'formatted with spaces, dashes and parentheses' => ['+52 (55) 1234-5678', '+525512345678'],
    'international 00 prefix' => ['0052 55 1234 5678', '+525512345678'],
    'ten digit Mexican national number' => ['55 1234 5678', '+525512345678'],
    'legacy Mexican mobile prefix' => ['+52 1 55 1234 5678', '+525512345678'],
    'WhatsApp id with legacy Mexican prefix' => ['+5215512345678', '+525512345678'],
    'foreign number with prefix' => ['+44 7700 900123', '+447700900123'],
    'dots as separators' => ['+1.415.555.2671', '+14155552671'],
]);

test('ten digit national numbers use the calling code of the default country', function () {
    expect(PhoneNumber::normalize('415 555 2671', 'US'))->toBe('+14155552671')
        ->and(PhoneNumber::normalize('415 555 2671', 'ZZ'))->toBeNull();
});

test('numbers that cannot be normalized reliably are rejected', function (?string $input) {
    expect(PhoneNumber::normalize($input))->toBeNull()
        ->and(PhoneNumber::isValid($input))->toBeFalse();
})->with([
    'empty' => [''],
    'null' => [null],
    'letters' => ['55-CALL-NOW'],
    'extension' => ['+52 55 1234 5678 ext 12'],
    'national number of the wrong length' => ['1234567'],
    'too short with prefix' => ['+1234567'],
    'too long' => ['+1234567890123456'],
    'plus sign in the middle' => ['52+5512345678'],
    'calling code starting with zero' => ['+0525512345678'],
]);
