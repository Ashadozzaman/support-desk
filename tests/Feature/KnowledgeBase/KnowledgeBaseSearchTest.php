<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Models\KbArticle;
use App\Models\User;
use App\Services\KnowledgeBaseService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * FULLTEXT only sees committed rows (see TicketFilterTest), so these tests
 * truncate instead of wrapping each test in a rolled-back transaction.
 */
uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->password = KbArticle::factory()->create([
        'title' => 'Reset your password',
        'slug' => 'reset-your-password',
        'body' => 'Choose "Forgot your password?" on the sign-in page and follow the link we email you.',
    ]);
    $this->attachments = KbArticle::factory()->create([
        'title' => 'Attach files to a reply',
        'slug' => 'attach-files',
        'body' => 'You can attach screenshots and PDF documents to any reply. The password field is not needed.',
    ]);
    $this->draft = KbArticle::factory()->draft()->create([
        'title' => 'Password policy draft',
        'slug' => 'password-policy',
        'body' => 'Password password password: rules for a strong password.',
    ]);
});

test('retrieval ranks the best match first and returns a relevance score', function () {
    $results = app(KnowledgeBaseService::class)->relevantArticles('How do I reset my password?');

    expect($results->pluck('id')->all())->toBe([$this->password->id, $this->attachments->id])
        ->and($results->first()->relevance)->toBeGreaterThan($results->last()->relevance)
        ->and($results->first()->body)->toContain('Forgot your password');
});

test('retrieval never returns unpublished articles', function () {
    $results = app(KnowledgeBaseService::class)->relevantArticles('password policy rules');

    expect($results->pluck('id'))->not->toContain($this->draft->id);
});

test('retrieval respects the limit', function () {
    expect(app(KnowledgeBaseService::class)->relevantArticles('password', 1))->toHaveCount(1);
});

test('questions with operator characters do not break the search', function () {
    $results = app(KnowledgeBaseService::class)->relevantArticles('+password -"reset" (sign-in)* @me');

    expect($results)->not->toBeEmpty();
});

test('too-short or empty questions return nothing without querying', function () {
    expect(app(KnowledgeBaseService::class)->relevantArticles('  a '))->toBeEmpty();
});

test('help-centre search is relevance-ordered and published only', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);

    $this->actingAs($customer)
        ->get(route('help.index', ['q' => 'reset password']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('help/index')
            ->where('query', 'reset password')
            ->has('articles.data', 2)
            ->where('articles.data.0.slug', 'reset-your-password')
            ->missing('articles.data.0.body'));
});
