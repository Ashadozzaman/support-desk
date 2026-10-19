<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
