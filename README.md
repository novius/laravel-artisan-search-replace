<div align="center">

# Laravel Artisan Search Replace

[![Novius CI](https://github.com/novius/laravel-artisan-search-replace/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/novius/laravel-artisan-search-replace/actions/workflows/ci.yml)
[![Packagist Release](https://img.shields.io/packagist/v/novius/laravel-artisan-search-replace.svg?maxAge=1800&style=flat-square)](https://packagist.org/packages/novius/laravel-artisan-search-replace)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](http://www.gnu.org/licenses/agpl-3.0)

</div>

## Introduction

This package provides a `db:search-replace` Artisan command to search and replace a string in all the text columns
of a database — typically to change a domain name after copying a production database to a staging environment.

Inspired by [WP-CLI `search-replace`](https://github.com/wp-cli/search-replace-command), it:

* handles **PHP serialized data** (string lengths are recomputed, nested serialized data included),
* handles **JSON data**, whatever the escaping of slashes and unicode characters (`https:\/\/example.com`,
  `é`…), and keeps JSON documents valid,
* is **case-sensitive** and never modifies primary keys,
* can write a **journal** of every change, to **revert** them later.

## Requirements

* PHP >= 8.2
* Laravel 12 or 13
* MySQL or MariaDB

## Installation

```bash
composer require novius/laravel-artisan-search-replace
```

You can optionally publish the config file:

```bash
php artisan vendor:publish --provider="Novius\LaravelArtisanSearchReplace\LaravelArtisanSearchReplaceServiceProvider" --tag="config"
```

## Usage

```bash
# Replace in every table
php artisan db:search-replace "https://www.example.com" "https://staging.example.com"

# See what would be replaced, without saving anything
php artisan db:search-replace "https://www.example.com" "https://staging.example.com" --dry-run

# Only in some tables (wildcards allowed)
php artisan db:search-replace "old" "new" posts "blog_*"

# Remove a string
php artisan db:search-replace "remove-me" ""

# Strings starting with dashes go after the "--" separator
php artisan db:search-replace -- "--old" "--new"
```

| Option                     | Description                                                                                       |
|----------------------------|---------------------------------------------------------------------------------------------------|
| `--database=`              | The database connection to use.                                                                   |
| `--skip-tables=`           | Comma-separated list of tables to skip (wildcards allowed).                                       |
| `--skip-columns=`          | Comma-separated list of columns to skip: `column` (in every table) or `table.column`.             |
| `--include-columns=`       | Comma-separated list of the only columns to process: `column` or `table.column`.                  |
| `--journal=`               | Write every change to this file, to be able to revert them (see below).                          |
| `--dry-run`                | Run the search/replace and report the changes, without saving them.                              |
| `--force`                  | Do not ask for a confirmation in production.                                                      |
| `-v`                       | Also report the processed columns without replacement.                                           |

Table names are given without the connection table prefix.

### Revert the changes

With the `--journal` option, every changed value is written to a [JSON Lines](https://jsonlines.org/) file,
**before** being saved in the database:

```bash
php artisan db:search-replace "https://www.example.com" "https://staging.example.com" --journal=storage/search-replace.jsonl
```

The changes can then be reverted with:

```bash
php artisan db:search-replace:revert storage/search-replace.jsonl
```

A value is restored only if it has not been modified since the search-replace. Otherwise, it is left untouched and
reported as a conflict (use `-v` to list them), and the command fails. Reverting twice is harmless: the values
already reverted are reported as such.

The revert command also accepts `--database`, `--dry-run` and `--force` (which also skips the confirmation when
the current database is not the one of the journal).

> [!WARNING]
> The journal contains the full content of the modified values: store it as carefully as your database dumps.

## How it works

* Only the tables having a **primary key** are processed (the others are reported), as each modified row is updated
  by its primary key.
* In each table, only the text columns are processed (`char`, `varchar`, `*text` and `json`), primary key and
  generated columns excluded.
* Only the rows containing the search string, in any of its forms, are fetched — by chunks, paginated on the
  primary key — then processed in PHP.
* Values that look like PHP serialized data but cannot be unserialized are **left untouched** and reported.
* Objects of classes not allowed to be unserialized (see `unserialize_allowed_classes`) are left untouched, the rest
  of the serialized data being processed.
* The changes are made with the query builder: Eloquent model events are not dispatched, and caches or search
  indexes holding the replaced data must be refreshed (e.g. `php artisan cache:clear`).

## Configuration

| Key                           | Description                                                                                          |
|-------------------------------|------------------------------------------------------------------------------------------------------|
| `skip_tables`                 | Tables never processed, unless explicitly named in the command arguments (`migrations`, `cache`, `sessions`, `jobs`…). |
| `skip_columns`                | Columns never processed (`password`, `remember_token`).                                               |
| `unserialize_allowed_classes` | Classes allowed when unserializing PHP serialized data (`stdClass` by default).                       |
| `chunk_size`                  | Number of rows fetched at once (1000 by default).                                                    |
| `drivers`                     | Implementation of the database specific parts, by connection driver name.                           |

### Database drivers

The database specific parts (listing the tables, recognizing the text columns, searching the rows containing the
string) are implemented by a class per database engine, implementing
`Novius\LaravelArtisanSearchReplace\Drivers\Driver`. Only MySQL and MariaDB are provided, but you can register your
own implementation in the `drivers` configuration key.

## Prohibiting the commands

As other destructive Laravel commands, the commands can be prohibited, e.g. in production:

```php
use Novius\LaravelArtisanSearchReplace\Console\SearchReplaceCommand;
use Novius\LaravelArtisanSearchReplace\Console\SearchReplaceRevertCommand;

// In the boot() method of a service provider
SearchReplaceCommand::prohibit($this->app->isProduction());
SearchReplaceRevertCommand::prohibit($this->app->isProduction());
```

## Testing

The tests need a MySQL or MariaDB database. By default, they connect to `search_replace_test` on `127.0.0.1:3306` as
`root` / `password`: override the `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` environment
variables (or create a `phpunit.xml` file from `phpunit.xml.dist`) to use another server.

> [!CAUTION]
> The tests drop all the tables of the test database.

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
