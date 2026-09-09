<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class UrlSafeCredential implements ValidationRule
{
    /**
     * Characters that cannot survive being embedded in an Xtream stream path
     * (`/live/{username}/{password}/{id}.{ext}`): a slash or backslash splits
     * the segment so the route never matches, `?` and `#` terminate the path,
     * `%` is decoded by the router, and whitespace is rejected or re-encoded
     * by most players. Rejecting them at input time is the practical place to
     * catch this: once such a credential is stored the stream route simply
     * fails to match, so the device just sees a generic 404 with no hint that
     * the credential itself is at fault.
     */
    private const PATTERN = '/[\s\/\\\\?#%]/u';

    public static function isSafe(string $value): bool
    {
        // preg_match() returns 1 on match, 0 on no match, and false on error
        // (notably malformed UTF-8 under the `/u` modifier). Only an explicit
        // 0 means the value is clean; treat a match or a scan error as unsafe
        // so a credential with invalid bytes cannot fail open.
        return preg_match(self::PATTERN, $value) === 0;
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=):PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (! self::isSafe($value)) {
            $fail(__('The :attribute cannot contain spaces or the characters / \\ ? # % because it is embedded in stream URLs.'));
        }
    }
}
