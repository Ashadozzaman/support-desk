<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Swaps a log channel's handlers for an in-memory TestHandler so a test can
 * read the exact records written — including `extra` (Laravel Context, e.g.
 * request_id), which Log::spy() can't see.
 */
function captureLog(string $channel): TestHandler
{
    $handler = new TestHandler;

    Log::forgetChannel($channel);
    Log::channel($channel)->getLogger()->setHandlers([$handler]);

    return $handler;
}

/**
 * The asset version Inertia expects in `X-Inertia-Version`, asked from the
 * middleware itself rather than by hashing public/build/manifest.json: CI runs
 * the tests before `npm run build`, so the manifest may not exist (the
 * middleware then has no version, and an empty header matches it).
 */
function inertiaVersion(): string
{
    return (string) app(HandleInertiaRequests::class)->version(Request::create('/'));
}

/**
 * A raw RFC 822 email for the mailbox tests.
 *
 * @param  array<string, string>  $headers  extra/overriding headers
 * @param  array<string, string>  $attachments  filename => contents
 */
function rawEmail(array $headers = [], string $body = 'Hello, my order never arrived.', array $attachments = [], ?string $html = null): string
{
    $headers = array_merge([
        'Message-ID' => '<'.Str::uuid().'@mail.example.com>',
        'From' => '"Jane Doe" <jane@example.com>',
        'To' => 'support@example.com',
        'Subject' => 'Order missing',
        'Date' => 'Mon, 19 Oct 2026 10:00:00 +0000',
        'MIME-Version' => '1.0',
    ], $headers);
    $headers = array_filter($headers, fn (string $value) => $value !== '');

    $parts = [$html === null
        ? "Content-Type: text/plain; charset=utf-8\r\n\r\n{$body}\r\n"
        : "Content-Type: text/html; charset=utf-8\r\n\r\n{$html}\r\n"];

    foreach ($attachments as $name => $contents) {
        $parts[] = "Content-Type: application/octet-stream; name=\"{$name}\"\r\n"
            ."Content-Disposition: attachment; filename=\"{$name}\"\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode($contents))."\r\n";
    }

    $head = '';
    foreach ($headers as $name => $value) {
        $head .= "{$name}: {$value}\r\n";
    }

    return $head."Content-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
        .implode('', array_map(fn (string $part) => "--b1\r\n{$part}", $parts))
        ."--b1--\r\n";
}
