<?php

declare(strict_types=1);

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Category;
use App\Models\KbArticle;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function kbUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function articlePayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Export your tickets',
        'slug' => 'export-your-tickets',
        'body' => 'Open Reports and choose Export.',
        'is_published' => true,
        'category_id' => null,
    ], $overrides);
}

test('admin creates, edits and deletes an article', function () {
    $admin = kbUser(RoleEnum::Admin->value);
    $category = Category::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.kb-articles.store'), articlePayload(['category_id' => $category->id]))
        ->assertRedirect(route('admin.kb-articles.index'));

    $article = KbArticle::sole();
    expect($article->slug)->toBe('export-your-tickets')
        ->and($article->category_id)->toBe($category->id)
        ->and($article->is_published)->toBeTrue();

    $this->actingAs($admin)
        ->put(route('admin.kb-articles.update', $article), articlePayload(['title' => 'Export tickets to CSV', 'is_published' => false]))
        ->assertRedirect(route('admin.kb-articles.index'));

    expect($article->fresh())->title->toBe('Export tickets to CSV')->is_published->toBeFalse();

    $this->actingAs($admin)->delete(route('admin.kb-articles.destroy', $article))->assertRedirect();

    expect(KbArticle::count())->toBe(0);
});

test('admin list shows drafts and published, without article bodies', function () {
    KbArticle::factory()->create();
    KbArticle::factory()->draft()->create();

    $this->actingAs(kbUser(RoleEnum::Admin->value))
        ->get(route('admin.kb-articles.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/kb-articles/index')
            ->has('articles.data', 2)
            ->has('articles.data.0.excerpt')
            ->missing('articles.data.0.body'));
});

test('slug must be unique and URL-safe', function (string $slug) {
    KbArticle::factory()->create(['slug' => 'taken']);

    $this->actingAs(kbUser(RoleEnum::Admin->value))
        ->post(route('admin.kb-articles.store'), articlePayload(['slug' => $slug]))
        ->assertSessionHasErrors('slug');
})->with(['taken', 'Has Spaces', 'trailing-', 'double--dash', 'ünïcode']);

test('editing keeps its own slug valid', function () {
    $article = KbArticle::factory()->create(['slug' => 'mine']);

    $this->actingAs(kbUser(RoleEnum::Admin->value))
        ->put(route('admin.kb-articles.update', $article), articlePayload(['slug' => 'mine']))
        ->assertSessionHasNoErrors();
});

test('agents and customers cannot manage articles', function (string $role) {
    $user = kbUser($role);
    $article = KbArticle::factory()->create();

    $this->actingAs($user)->get(route('admin.kb-articles.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.kb-articles.store'), articlePayload())->assertForbidden();
    $this->actingAs($user)->put(route('admin.kb-articles.update', $article), articlePayload())->assertForbidden();
    $this->actingAs($user)->delete(route('admin.kb-articles.destroy', $article))->assertForbidden();

    expect(KbArticle::count())->toBe(1);
})->with([RoleEnum::Agent->value, RoleEnum::Customer->value]);

test('a custom role with knowledge-base.manage can manage articles', function () {
    $role = Role::create(['name' => 'writer', 'guard_name' => 'web']);
    $role->givePermissionTo(PermissionEnum::KnowledgeBaseManage->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs(kbUser('writer'))->get(route('admin.kb-articles.index'))->assertOk();
});

test('everyone signed in reads published articles; drafts 404 except for editors', function () {
    $published = KbArticle::factory()->create(['slug' => 'live']);
    $draft = KbArticle::factory()->draft()->create(['slug' => 'secret']);
    $customer = kbUser(RoleEnum::Customer->value);
    $agent = kbUser(RoleEnum::Agent->value);

    $this->actingAs($customer)
        ->get(route('help.show', $published))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('help/show')
            ->where('article.data.body', $published->body)
            ->where('article.data.can.update', false));

    $this->actingAs($customer)->get(route('help.show', $draft))->assertNotFound();
    $this->actingAs($agent)->get(route('help.show', $draft))->assertNotFound();

    $this->actingAs(kbUser(RoleEnum::Admin->value))
        ->get(route('help.show', $draft))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('article.data.is_published', false));
});

test('help centre lists published articles A-Z for any signed-in user', function () {
    KbArticle::factory()->create(['title' => 'Zebra']);
    KbArticle::factory()->create(['title' => 'Apple']);
    KbArticle::factory()->draft()->create(['title' => 'Banana']);

    $this->actingAs(kbUser(RoleEnum::Customer->value))
        ->get(route('help.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('articles.data', 2)
            ->where('articles.data.0.title', 'Apple')
            ->where('articles.data.1.title', 'Zebra'));
});

test('guests are sent to login', function () {
    $this->get(route('help.index'))->assertRedirect(route('login'));
});
