<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('articles', function (Blueprint $table) {
        $table->id();
        $table->text('content')->nullable();
        $table->json('meta')->nullable();
    });
});

it('writes a journal and reverts the changes', function () {
    $first = DB::table('articles')->insertGetId([
        'content' => serialize(['url' => 'https://old.test']),
        'meta' => json_encode(['b' => 'https://old.test', 'a' => 1]),
    ]);
    $second = DB::table('articles')->insertGetId(['content' => 'https://old.test']);
    $before = DB::table('articles')->orderBy('id')->get();
    $path = journalPath();

    $this->artisan('db:search-replace', ['search' => 'https://old.test', 'replace' => 'https://new.test', '--journal' => $path])
        ->expectsOutputToContain('db:search-replace:revert')
        ->assertSuccessful();

    $lines = array_map(fn ($line) => json_decode($line, true), file($path));
    expect($lines)->toHaveCount(4)
        ->and($lines[0])->toMatchArray(['type' => 'header', 'format' => 1, 'search' => 'https://old.test', 'dry_run' => false])
        ->and($lines[1])->toMatchArray(['type' => 'change', 'table' => 'articles', 'keys' => ['id' => $first], 'column' => 'content'])
        ->and(DB::table('articles')->find($second)->content)->toBe('https://new.test');

    $this->artisan('db:search-replace:revert', ['journal' => $path])
        ->expectsOutputToContain('Restored 3 values.')
        ->assertSuccessful();

    expect(DB::table('articles')->orderBy('id')->get())->toEqual($before);
});

it('does not revert values modified since the search-replace', function () {
    $modified = DB::table('articles')->insertGetId(['content' => 'foo']);
    $other = DB::table('articles')->insertGetId(['content' => 'foo']);
    $path = journalPath();
    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar', '--journal' => $path])->assertSuccessful();

    DB::table('articles')->where('id', $modified)->update(['content' => 'edited']);

    $this->artisan('db:search-replace:revert', ['journal' => $path, '-v' => true])
        ->expectsOutputToContain("articles.content [id=$modified]: modified since the search-replace")
        ->expectsOutputToContain('Restored 1 value.')
        ->assertFailed();

    expect(DB::table('articles')->find($modified)->content)->toBe('edited')
        ->and(DB::table('articles')->find($other)->content)->toBe('foo');

    // A second revert finds the values already reverted
    $this->artisan('db:search-replace:revert', ['journal' => $path])
        ->expectsOutputToContain('Restored 0 values.');
});

it('journals and reverts rows with binary primary keys', function () {
    Schema::create('files', function (Blueprint $table) {
        $table->binary('id', 16, true)->primary();
        $table->string('path');
    });
    $id = "\xFF\x00".random_bytes(14);
    DB::table('files')->insert(['id' => $id, 'path' => '/foo/file.txt']);
    $path = journalPath();

    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar', 'tables' => ['files'], '--journal' => $path])->assertSuccessful();
    expect(file($path)[1])->toContain('"encoding":"base64"')
        ->and(DB::table('files')->where('id', $id)->value('path'))->toBe('/bar/file.txt');

    $this->artisan('db:search-replace:revert', ['journal' => $path])
        ->expectsOutputToContain('Restored 1 value.')
        ->assertSuccessful();
    expect(DB::table('files')->where('id', $id)->value('path'))->toBe('/foo/file.txt');
});

it('reports what would be reverted in dry-run mode', function () {
    $id = DB::table('articles')->insertGetId(['content' => 'foo']);
    $path = journalPath();
    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar', '--journal' => $path])->assertSuccessful();

    $this->artisan('db:search-replace:revert', ['journal' => $path, '--dry-run' => true])
        ->expectsOutputToContain('1 value to be restored')
        ->assertSuccessful();

    expect(DB::table('articles')->find($id)->content)->toBe('bar');
});

it('refuses a journal written by a dry run', function () {
    DB::table('articles')->insert(['content' => 'foo']);
    $path = journalPath();
    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar', '--journal' => $path, '--dry-run' => true])->assertSuccessful();

    $this->artisan('db:search-replace:revert', ['journal' => $path])
        ->expectsOutputToContain('written by a dry run')
        ->assertFailed();
});

it('refuses invalid journals', function () {
    $path = journalPath();
    file_put_contents($path, "not a journal\n");

    $this->artisan('db:search-replace:revert', ['journal' => $path])
        ->expectsOutputToContain('is not a valid search-replace journal')
        ->assertFailed();

    $this->artisan('db:search-replace:revert', ['journal' => '/does/not/exist.jsonl'])
        ->expectsOutputToContain('Unable to read the journal file')
        ->assertFailed();
});

it('asks for a confirmation to revert on another database', function () {
    $path = journalPath();
    file_put_contents($path, json_encode(['type' => 'header', 'format' => 1, 'connection' => 'mysql', 'database' => 'another_database', 'dry_run' => false])."\n");

    $this->artisan('db:search-replace:revert', ['journal' => $path])
        ->expectsConfirmation('Do you really want to revert it on this database?', 'no')
        ->assertFailed();
});
