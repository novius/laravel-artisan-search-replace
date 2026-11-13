<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Novius\LaravelArtisanSearchReplace\Console\SearchReplaceCommand;

beforeEach(function () {
    Schema::create('posts', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->text('content')->nullable();
        $table->json('meta')->nullable();
        $table->string('upper_title')->storedAs('upper(title)');
    });
});

function insertPost(string $title, ?string $content = null, ?string $meta = null): int
{
    return DB::table('posts')->insertGetId(['title' => $title, 'content' => $content, 'meta' => $meta]);
}

it('registers the commands', function () {
    expect(Artisan::all())->toHaveKeys(['db:search-replace', 'db:search-replace:revert']);
});

it('replaces the string in every text column, case-sensitively', function () {
    $id = insertPost('Hello old.test', 'Visit https://old.test and OLD.TEST');
    insertPost('Untouched');

    $this->artisan('db:search-replace', ['search' => 'old.test', 'replace' => 'new.test'])
        ->expectsOutputToContain('Made 2 replacements.')
        ->assertSuccessful();

    expect(DB::table('posts')->find($id))
        ->title->toBe('Hello new.test')
        ->content->toBe('Visit https://new.test and OLD.TEST')
        ->upper_title->toBe('HELLO NEW.TEST');
});

it('searches LIKE wildcards and backslashes literally', function () {
    $exact = insertPost('Title', 'save 50%_off\now');
    $wildcard = insertPost('Title', 'save 50%Xoff\now and 50abcoffXnow');

    $this->artisan('db:search-replace', ['search' => '50%_off\n', 'replace' => 'half '])
        ->expectsOutputToContain('Made 1 replacement.')
        ->assertSuccessful();

    expect(DB::table('posts')->find($exact)->content)->toBe('save half ow')
        ->and(DB::table('posts')->find($wildcard)->content)->toBe('save 50%Xoff\now and 50abcoffXnow');
});

it('handles PHP serialized and JSON data', function () {
    $id = insertPost(
        'Title',
        serialize(['url' => 'https://old.test/a']),
        json_encode(['url' => 'https://old.test/b']),
    );
    DB::table('posts')->where('id', $id)->update(['title' => json_encode(['url' => 'https://old.test/c'])]);

    $this->artisan('db:search-replace', ['search' => 'https://old.test', 'replace' => 'https://www.new.test'])
        ->assertSuccessful();

    $post = DB::table('posts')->find($id);
    expect(unserialize($post->content))->toBe(['url' => 'https://www.new.test/a'])
        ->and(json_decode($post->meta, true))->toBe(['url' => 'https://www.new.test/b'])
        ->and($post->title)->toBe('{"url":"https:\/\/www.new.test\/c"}');
});

it('does not save anything in dry-run mode', function () {
    insertPost('foo', 'foo foo');

    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar', '--dry-run' => true])
        ->expectsOutputToContain('2 replacements to be made')
        ->assertSuccessful();

    expect(DB::table('posts')->value('content'))->toBe('foo foo');
});

it('can remove the search string', function () {
    insertPost('foo', 'a -remove-me-b');

    $this->artisan('db:search-replace', ['search' => '-remove-me-', 'replace' => ''])->assertSuccessful();

    expect(DB::table('posts')->value('content'))->toBe('a b');
});

it('accepts strings starting with dashes after the -- separator', function () {
    insertPost('foo', 'a --old b');

    Artisan::call('db:search-replace -- --old --new');

    expect(DB::table('posts')->value('content'))->toBe('a --new b');
});

it('does nothing when the replacement is identical to the search string', function () {
    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'foo'])
        ->expectsOutputToContain('nothing to do')
        ->assertSuccessful();
});

it('restricts the replacement to the given tables, with wildcards', function () {
    Schema::create('blog_posts', fn (Blueprint $table) => $table->id() && $table->string('title'));
    Schema::create('blog_tags', fn (Blueprint $table) => $table->id() && $table->string('title'));
    DB::table('blog_posts')->insert(['title' => 'foo']);
    DB::table('blog_tags')->insert(['title' => 'foo']);
    insertPost('foo');

    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar', 'tables' => ['blog_*'], '--skip-tables' => 'blog_tags'])
        ->assertSuccessful();

    expect(DB::table('blog_posts')->value('title'))->toBe('bar')
        ->and(DB::table('blog_tags')->value('title'))->toBe('foo')
        ->and(DB::table('posts')->value('title'))->toBe('foo');
});

it('warns when a table pattern matches no table', function () {
    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar', 'tables' => ['unknown']])
        ->expectsOutputToContain('No table matches [unknown].')
        ->assertSuccessful();
});

it('skips the tables of the configuration, unless explicitly named', function () {
    Schema::create('sessions', fn (Blueprint $table) => $table->string('id')->primary() && $table->text('payload'));
    DB::table('sessions')->insert(['id' => 'a', 'payload' => 'foo']);

    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar'])->assertSuccessful();
    expect(DB::table('sessions')->value('payload'))->toBe('foo');

    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar', 'tables' => ['sessions']])->assertSuccessful();
    expect(DB::table('sessions')->value('payload'))->toBe('bar');
});

it('skips columns', function () {
    $id = insertPost('foo', 'foo', json_encode(['foo']));

    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar', '--skip-columns' => 'posts.content,meta'])->assertSuccessful();

    expect(DB::table('posts')->find($id))
        ->title->toBe('bar')
        ->content->toBe('foo')
        ->meta->toBe('["foo"]');
});

it('includes only some columns', function () {
    $id = insertPost('foo', 'foo', json_encode(['foo']));

    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar', '--include-columns' => 'content,posts.meta'])->assertSuccessful();

    expect(DB::table('posts')->find($id))
        ->title->toBe('foo')
        ->content->toBe('bar')
        ->meta->toBe('["bar"]');
});

it('skips the columns of the configuration', function () {
    Schema::create('users', fn (Blueprint $table) => $table->id() && $table->string('name') && $table->string('password'));
    DB::table('users')->insert(['name' => 'foo', 'password' => 'foo']);

    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar'])->assertSuccessful();

    expect(DB::table('users')->first())
        ->name->toBe('bar')
        ->password->toBe('foo');
});

it('never modifies primary keys', function () {
    Schema::create('pages', fn (Blueprint $table) => $table->string('slug')->primary() && $table->string('title'));
    DB::table('pages')->insert(['slug' => 'foo', 'title' => 'foo']);

    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar'])->assertSuccessful();

    expect(DB::table('pages')->first())
        ->slug->toBe('foo')
        ->title->toBe('bar');
});

it('skips and reports tables without primary key', function () {
    Schema::create('logs', fn (Blueprint $table) => $table->string('message'));
    DB::table('logs')->insert(['message' => 'foo']);

    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar'])
        ->expectsOutputToContain('Tables without primary key, skipped: logs.')
        ->assertSuccessful();

    expect(DB::table('logs')->value('message'))->toBe('foo');
});

it('paginates on composite primary keys', function () {
    config(['laravel-artisan-search-replace.chunk_size' => 2]);
    Schema::create('translations', function (Blueprint $table) {
        $table->string('locale');
        $table->unsignedInteger('number');
        $table->string('text');
        $table->primary(['locale', 'number']);
    });
    foreach (['en', 'fr'] as $locale) {
        foreach (range(1, 5) as $number) {
            DB::table('translations')->insert(['locale' => $locale, 'number' => $number, 'text' => "foo $locale $number"]);
        }
    }

    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar'])
        ->expectsOutputToContain('Made 10 replacements.')
        ->assertSuccessful();

    expect(DB::table('translations')->where('text', 'like', 'foo%')->count())->toBe(0);
});

it('leaves corrupted serialized data untouched and reports it', function () {
    $id = insertPost('foo', 'a:1:{i:0;s:10:"foo";}');

    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar'])
        ->expectsOutputToContain('value(s) left untouched')
        ->expectsOutputToContain("posts.content [id=$id]")
        ->assertSuccessful();

    expect(DB::table('posts')->find($id))
        ->title->toBe('bar')
        ->content->toBe('a:1:{i:0;s:10:"foo";}');
});

it('reports values that cannot be saved', function () {
    Schema::create('codes', fn (Blueprint $table) => $table->id() && $table->string('code', 5));
    DB::table('codes')->insert(['code' => 'foo']);

    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'foobarbaz'])
        ->expectsOutputToContain('update failed')
        ->assertSuccessful();

    expect(DB::table('codes')->value('code'))->toBe('foo');
});

it('refuses unsupported database drivers', function () {
    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar', '--database' => 'memory'])
        ->expectsOutputToContain('The [sqlite] database driver is not supported')
        ->assertFailed();
});

it('can be prohibited', function () {
    SearchReplaceCommand::prohibit();
    insertPost('foo');

    try {
        $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar'])->assertFailed();
    } finally {
        SearchReplaceCommand::prohibit(false);
    }

    expect(DB::table('posts')->value('title'))->toBe('foo');
});

it('refuses to overwrite an existing journal', function () {
    $path = journalPath();
    file_put_contents($path, 'existing');

    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar', '--journal' => $path])
        ->expectsOutputToContain('already exists')
        ->assertFailed();

    expect(file_get_contents($path))->toBe('existing');
});
