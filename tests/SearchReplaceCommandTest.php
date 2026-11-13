<?php

use Illuminate\Support\Facades\Artisan;

it('registers the db:search-replace command', function () {
    expect(Artisan::all())->toHaveKey('db:search-replace');
});

it('runs the db:search-replace command', function () {
    $this->artisan('db:search-replace', ['search' => 'foo', 'replace' => 'bar'])
        ->assertSuccessful();
});
