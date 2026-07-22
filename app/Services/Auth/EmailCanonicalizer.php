<?php

namespace App\Services\Auth;

use InvalidArgumentException;

class EmailCanonicalizer
{
    public function canonicalize(string $email): string
    {
        $email = trim($email);
        $separator = strrpos($email, '@');
        if ($separator === false) {
            throw new InvalidArgumentException('Invalid email address.');
        }

        $local = substr($email, 0, $separator);
        $domain = substr($email, $separator + 1);
        if ($local === '' || $domain === '' || preg_match('/[^\x20-\x7E]/', $local)) {
            throw new InvalidArgumentException('Invalid email address.');
        }

        if (preg_match('/[^\x00-\x7F]/', $domain)) {
            if (! function_exists('idn_to_ascii')) {
                throw new InvalidArgumentException('International email domains are unavailable.');
            }
            $domain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (! is_string($domain) || $domain === '') {
                throw new InvalidArgumentException('Invalid email domain.');
            }
        }

        $canonical = strtolower($local).'@'.strtolower($domain);
        if (strlen($canonical) > 191 || filter_var($canonical, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email address.');
        }

        return $canonical;
    }
}
