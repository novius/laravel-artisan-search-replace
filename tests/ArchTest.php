<?php

use Illuminate\Console\Command;
use Novius\LaravelArtisanSearchReplace\Drivers\Driver;
use Novius\LaravelArtisanSearchReplace\Drivers\DriverResolver;

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->each->not->toBeUsed();

arch('console classes are Artisan commands')
    ->expect('Novius\LaravelArtisanSearchReplace\Console')
    ->toExtend(Command::class);

arch('drivers implement the Driver interface')
    ->expect('Novius\LaravelArtisanSearchReplace\Drivers')
    ->classes()
    ->toImplement(Driver::class)
    ->ignoring(DriverResolver::class);

arch('exceptions are runtime exceptions')
    ->expect('Novius\LaravelArtisanSearchReplace\Exceptions')
    ->toExtend(RuntimeException::class);
