<?php

use Illuminate\Console\Command;

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->each->not->toBeUsed();

arch('console classes are Artisan commands')
    ->expect('Novius\LaravelArtisanSearchReplace\Console')
    ->toExtend(Command::class);
