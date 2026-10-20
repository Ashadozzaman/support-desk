<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * Strips personal data from text before it is sent to an AI provider
 * (ENHANCEMENTS.md §3.2 rule 4). Deliberately blunt: a false positive costs
 * the model a few characters of context, a false negative leaks data.
 *
 * Ticket numbers (TKT-2026-000123) and ISO dates are not personal data and
 * are often what the question is about, but the digit rules below would
 * eat them — so they are split out first and left untouched.
 */
class PiiRedactor
{
    private const KEEP = '/(TKT-\d{4}-\d+|\b\d{4}-\d{2}-\d{2}\b)/';

    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    // An optional "+", then digits that may be broken up by spaces, dots,
    // dashes or parentheses: +880 1712-345678, (555) 123-4567.
    private const PHONE = '/\+?\(?\d[\d\s().\-]{6,}\d/';

    // Whatever is left: card, account and national-id numbers.
    private const LONG_DIGITS = '/\d{6,}/';

    public function redact(string $text): string
    {
        $parts = preg_split(self::KEEP, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        // With a capturing group, odd indexes are the kept matches.
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = $this->redactPart($part);
            }
        }

        return implode('', $parts);
    }

    private function redactPart(string $text): string
    {
        // Emails first: they can contain digit runs of their own.
        $text = (string) preg_replace(self::EMAIL, '[email]', $text);

        $text = (string) preg_replace_callback(
            self::PHONE,
            // Only a phone number when it has 8+ digits; "v2.1.3" or "10 - 12" stay.
            fn (array $match): string => strlen((string) preg_replace('/\D/', '', $match[0])) >= 8 ? '[phone]' : $match[0],
            $text,
        );

        return (string) preg_replace(self::LONG_DIGITS, '[number]', $text);
    }
}
