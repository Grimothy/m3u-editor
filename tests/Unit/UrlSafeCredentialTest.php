<?php

use App\Rules\UrlSafeCredential;
use App\Services\PasswordGeneratorService;
use Illuminate\Support\Facades\Validator;

function validateCredential(?string $value): bool
{
    return Validator::make(['password' => $value], ['password' => [new UrlSafeCredential]])->passes();
}

it('accepts credentials that survive being embedded in a stream path', function (string $value) {
    expect(validateCredential($value))->toBeTrue();
})->with([
    'alphanumeric' => 'tvuser123',
    'punctuation that is path safe' => 'a.b-c_d~e!$&*+,;=:@()',
    'unicode' => 'Zuschauer_ß_日本',
    'generated password' => PasswordGeneratorService::generate(),
]);

it('rejects credentials containing characters that break stream URLs', function (string $value) {
    expect(validateCredential($value))->toBeFalse();
})->with([
    'forward slash' => 'pass/word',
    'server url pasted as password' => 'https://m3u-editor.example.ts.net',
    'backslash' => 'pass\\word',
    'question mark' => 'pass?word',
    'hash' => 'pass#word',
    'percent' => 'pass%20word',
    'space' => 'pass word',
    'tab' => "pass\tword",
    'newline' => "pass\nword",
    'malformed utf-8 with a slash' => "bad\xffslash/here",
    'malformed utf-8 alone' => "pass\xffword",
]);

it('leaves empty values to the required/nullable rules', function () {
    expect(validateCredential(''))->toBeTrue()
        ->and(validateCredential(null))->toBeTrue();
});
