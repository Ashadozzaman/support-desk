<?php

declare(strict_types=1);

use App\Support\Ai\PiiRedactor;

dataset('pii', [
    'email' => ['Contact me at jane.doe+billing@example.co.uk please', 'Contact me at [email] please'],
    'international phone' => ['Call +880 1712-345678 after 5', 'Call [phone] after 5'],
    'local phone with brackets' => ['My number is (555) 123-4567.', 'My number is [phone].'],
    'card number with spaces' => ['Card 4111 1111 1111 1111 was charged', 'Card [phone] was charged'],
    'long digit run' => ['Account 12345678901 is locked', 'Account [phone] is locked'],
    'six digits' => ['Order 123456 is missing', 'Order [number] is missing'],
    'ticket number kept' => ['See TKT-2026-000123 for details', 'See TKT-2026-000123 for details'],
    'iso date kept' => ['Since 2026-10-19 it fails', 'Since 2026-10-19 it fails'],
    'short numbers kept' => ['Version 2.1.3 fails on 10 - 12 files', 'Version 2.1.3 fails on 10 - 12 files'],
    'email next to ticket number' => ['TKT-2026-000001 from a@b.io', 'TKT-2026-000001 from [email]'],
]);

test('redacts personal data and keeps support context', function (string $input, string $expected) {
    expect((new PiiRedactor)->redact($input))->toBe($expected);
})->with('pii');
