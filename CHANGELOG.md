# Changelog

All notable changes to `novius/laravel-artisan-search-replace` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/novius/laravel-artisan-search-replace/commits/main)

### Added

- `db:search-replace` command, to search and replace a string in all the text columns of a MySQL or MariaDB database,
  PHP serialized and JSON data included, with tables and columns selection, dry-run mode and production confirmation.
- `--journal` option, writing every change to a JSON Lines file, and `db:search-replace:revert` command, reverting
  the changes of a journal that were not modified since.
- Database specific parts implemented by a driver class per database engine, configurable.
