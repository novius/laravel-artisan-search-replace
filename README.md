<div align="center">

# Laravel Artisan Search Replace

[![Novius CI](https://github.com/novius/laravel-artisan-search-replace/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/novius/laravel-artisan-search-replace/actions/workflows/ci.yml)
[![Packagist Release](https://img.shields.io/packagist/v/novius/laravel-artisan-search-replace.svg?maxAge=1800&style=flat-square)](https://packagist.org/packages/novius/laravel-artisan-search-replace)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](http://www.gnu.org/licenses/agpl-3.0)

</div>

## Introduction

This package provides a `db:search-replace` Artisan command to search and replace a string in all text columns of a database.

## Requirements

* PHP >= 8.2
* Laravel 12 or 13

## Installation

You can install the package via composer:

```bash
composer require novius/laravel-artisan-search-replace
```

## Configuration

You can optionally publish the config file with:

```bash
php artisan vendor:publish --provider="Novius\LaravelArtisanSearchReplace\LaravelArtisanSearchReplaceServiceProvider" --tag="config"
```

## Usage

```bash
php artisan db:search-replace "search" "replace"
```

## Testing

```bash
composer test
```

## Lint

Run Laravel Pint to lint your code:

```bash
composer lint
```

## Static analysis

```bash
composer analyse
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security

Please see [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## License

This package is under [GNU Affero General Public License v3](http://www.gnu.org/licenses/agpl-3.0.html) or (at your option) any later version.
