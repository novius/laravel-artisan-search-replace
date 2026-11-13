<?php

namespace Novius\LaravelArtisanSearchReplace\Drivers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Novius\LaravelArtisanSearchReplace\Exceptions\UnsupportedDriverException;

class DriverResolver
{
    /**
     * @param  array<string, class-string<Driver>>  $drivers  Driver classes, by Laravel connection driver name
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $drivers,
    ) {}

    /**
     * @throws UnsupportedDriverException
     */
    public function resolve(Connection $connection): Driver
    {
        $name = $connection->getDriverName();

        if (! isset($this->drivers[$name])) {
            throw new UnsupportedDriverException(sprintf(
                'The [%s] database driver is not supported (supported: %s).',
                $name,
                implode(', ', array_keys($this->drivers)) ?: 'none',
            ));
        }

        return $this->container->make($this->drivers[$name]);
    }
}
