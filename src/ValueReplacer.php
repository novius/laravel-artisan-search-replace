<?php

namespace Novius\LaravelArtisanSearchReplace;

use __PHP_Incomplete_Class;
use InvalidArgumentException;
use JsonException;
use Novius\LaravelArtisanSearchReplace\Exceptions\UnprocessableValueException;
use SplObjectStorage;
use stdClass;
use Throwable;
use UnitEnum;

/**
 * Replaces a string in a single value, handling:
 *  - plain text,
 *  - JSON documents (whatever the escaping of slashes and unicode characters),
 *  - JSON-escaped occurrences in plain text (e.g. "https:\/\/example.com" in an inline script),
 *  - PHP serialized data (recursively, string lengths being recomputed).
 */
class ValueReplacer
{
    private const MAX_DEPTH = 128;

    private const JSON_FLAG_VARIANTS = [
        0,
        JSON_UNESCAPED_SLASHES,
        JSON_UNESCAPED_UNICODE,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ];

    /**
     * Replacement pairs applied to plain text.
     *
     * @var array<string, string>
     */
    private array $plainPairs;

    /**
     * Every JSON-escaped form of the search string.
     *
     * @var list<string>
     */
    private array $jsonSearches;

    /**
     * Replacement pairs applied to JSON documents, by JSON encoding flags.
     *
     * @var array<int, array<string, string>>
     */
    private array $jsonPairs = [];

    /**
     * Every form of the search string that can be found in a value.
     *
     * @var list<string>
     */
    private array $needles;

    private int $changes = 0;

    /**
     * @param  list<class-string>|bool  $allowedClasses  Classes allowed when unserializing PHP serialized data
     */
    public function __construct(
        public readonly string $search,
        public readonly string $replace,
        private readonly array|bool $allowedClasses = [stdClass::class],
    ) {
        if ($search === '') {
            throw new InvalidArgumentException('The search string cannot be empty.');
        }

        $this->plainPairs = [$search => $replace];
        $escapedSearch = self::jsonEscape($search);
        $escapedReplace = self::jsonEscape($replace);
        if ($escapedSearch !== null && $escapedReplace !== null && $escapedSearch !== $search) {
            $this->plainPairs[$escapedSearch] = $escapedReplace;
        }

        $jsonSearches = [];
        foreach (self::JSON_FLAG_VARIANTS as $flags) {
            $escaped = self::jsonEscape($search, $flags);
            if ($escaped !== null) {
                $jsonSearches[] = $escaped;
            }
        }
        $this->jsonSearches = array_values(array_unique($jsonSearches));

        $this->needles = array_values(array_unique([
            ...array_map(strval(...), array_keys($this->plainPairs)),
            ...$this->jsonSearches,
        ]));
    }

    /**
     * Every form of the search string that can be found in a value (plain and JSON-escaped).
     * A value containing none of them is never changed.
     *
     * @return list<string>
     */
    public function needles(): array
    {
        return $this->needles;
    }

    /**
     * Returns the value with the replacements applied, or the very same value if nothing was replaced.
     *
     * @throws UnprocessableValueException When the value cannot be processed safely
     */
    public function replace(string $value): string
    {
        if (! $this->mightContain($value)) {
            return $value;
        }

        $changesBefore = $this->changes;
        $result = $this->replaceString($value, 0);

        return $this->changes === $changesBefore ? $value : $result;
    }

    private function mightContain(string $value): bool
    {
        foreach ($this->needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function replaceString(string $value, int $depth): string
    {
        if ($depth > self::MAX_DEPTH || ! $this->mightContain($value)) {
            return $value;
        }

        if (self::looksSerialized($value)) {
            return $this->replaceSerialized($value, $depth);
        }

        // strtr() replaces the longest matches first and never re-processes replaced parts
        $result = strtr($value, self::isJsonDocument($value) ? $this->jsonPairsFor($value) : $this->plainPairs);
        if ($result !== $value) {
            $this->changes++;
        }

        return $result;
    }

    private function replaceSerialized(string $value, int $depth): string
    {
        try {
            $data = @unserialize($value, ['allowed_classes' => $this->allowedClasses]);
        } catch (Throwable $e) {
            throw new UnprocessableValueException('Serialized data could not be unserialized: '.$e->getMessage(), previous: $e);
        }

        if ($data === false && $value !== serialize(false)) {
            throw new UnprocessableValueException('The value looks like serialized data but could not be unserialized.');
        }

        $changesBefore = $this->changes;
        $data = $this->walk($data, $depth + 1, new SplObjectStorage);

        // Only re-serialize when something changed, as serialize(unserialize($x)) is not always identical to $x
        return $this->changes === $changesBefore ? $value : serialize($data);
    }

    /**
     * @param  SplObjectStorage<object, mixed>  $visited
     */
    private function walk(mixed $data, int $depth, SplObjectStorage $visited): mixed
    {
        if (is_string($data)) {
            return $this->replaceString($data, $depth);
        }

        if ($depth > self::MAX_DEPTH) {
            return $data;
        }

        if (is_array($data)) {
            foreach ($data as $key => $item) {
                $data[$key] = $this->walk($item, $depth + 1, $visited);
            }

            return $data;
        }

        // Objects of classes not allowed to be unserialized (__PHP_Incomplete_Class) are left untouched
        if (! is_object($data) || $data instanceof __PHP_Incomplete_Class || $data instanceof UnitEnum || $visited->contains($data)) {
            return $data;
        }

        $visited->attach($data);
        foreach (get_object_vars($data) as $property => $item) {
            $replaced = $this->walk($item, $depth + 1, $visited);
            if ($replaced !== $item) {
                try {
                    $data->{$property} = $replaced;
                } catch (Throwable $e) {
                    throw new UnprocessableValueException(sprintf('The property "%s" of a serialized %s object cannot be modified: %s', $property, $data::class, $e->getMessage()), previous: $e);
                }
            }
        }

        return $data;
    }

    /**
     * In a JSON document, every escaped form of the search string is replaced
     * by the replacement string escaped the same way as the document.
     *
     * @return array<string, string>
     */
    private function jsonPairsFor(string $document): array
    {
        $flags = 0;
        if (str_contains($document, '/') && ! str_contains($document, '\/')) {
            $flags |= JSON_UNESCAPED_SLASHES;
        }
        if (preg_match('/[\x80-\xFF]/', $document) === 1) {
            $flags |= JSON_UNESCAPED_UNICODE;
        }

        if (! isset($this->jsonPairs[$flags])) {
            $replace = self::jsonEscape($this->replace, $flags);
            $this->jsonPairs[$flags] = $replace === null ? $this->plainPairs : array_fill_keys($this->jsonSearches, $replace);
        }

        return $this->jsonPairs[$flags];
    }

    private static function looksSerialized(string $value): bool
    {
        return preg_match('/^(?:[aOCE]:\d+:|s:\d+:"|[bid]:[^;]*;|N;)/', $value) === 1
            && in_array(substr($value, -1), [';', '}'], true);
    }

    private static function isJsonDocument(string $value): bool
    {
        $trimmed = ltrim($value);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return false;
        }

        if (function_exists('json_validate')) {
            return json_validate($value);
        }

        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Returns the JSON-encoded form of a string, without its surrounding quotes, or null if it is not valid UTF-8.
     */
    private static function jsonEscape(string $value, int $flags = 0): ?string
    {
        try {
            return substr(json_encode($value, $flags | JSON_THROW_ON_ERROR), 1, -1);
        } catch (JsonException) {
            return null;
        }
    }
}
