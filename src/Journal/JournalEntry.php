<?php

namespace Novius\LaravelArtisanSearchReplace\Journal;

use Novius\LaravelArtisanSearchReplace\Exceptions\InvalidJournalException;

/**
 * A value changed by the search-replace.
 */
class JournalEntry
{
    /**
     * @param  string  $table  Name of the table, without the connection table prefix
     * @param  array<string, mixed>  $keys  Primary key values of the row
     */
    public function __construct(
        public readonly string $table,
        public readonly array $keys,
        public readonly string $column,
        public readonly string $old,
        public readonly string $new,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        // Values that are not valid UTF-8 cannot be written in JSON: they are base64 encoded
        $binary = ! mb_check_encoding($this->old, 'UTF-8') || ! mb_check_encoding($this->new, 'UTF-8');
        foreach ($this->keys as $value) {
            $binary = $binary || (is_string($value) && ! mb_check_encoding($value, 'UTF-8'));
        }

        $encode = static fn (mixed $value): mixed => $binary && is_string($value) ? base64_encode($value) : $value;

        $data = [
            'type' => 'change',
            'table' => $this->table,
            'keys' => array_map($encode, $this->keys),
            'column' => $this->column,
            'old' => $encode($this->old),
            'new' => $encode($this->new),
        ];

        if ($binary) {
            $data['encoding'] = 'base64';
        }

        return $data;
    }

    /**
     * @param  array<mixed>  $data
     *
     * @throws InvalidJournalException
     */
    public static function fromArray(array $data): self
    {
        if (($data['type'] ?? null) !== 'change'
            || ! is_string($data['table'] ?? null)
            || ! is_string($data['column'] ?? null)
            || ! is_array($data['keys'] ?? null) || $data['keys'] === []
            || ! is_string($data['old'] ?? null)
            || ! is_string($data['new'] ?? null)
        ) {
            throw new InvalidJournalException('Malformed journal entry.');
        }

        $binary = ($data['encoding'] ?? null) === 'base64';
        $decode = static function (mixed $value) use ($binary): mixed {
            if (! $binary || ! is_string($value)) {
                return $value;
            }

            $decoded = base64_decode($value, true);
            if ($decoded === false) {
                throw new InvalidJournalException('Malformed base64 value in journal entry.');
            }

            return $decoded;
        };

        $keys = [];
        foreach ($data['keys'] as $key => $value) {
            $keys[(string) $key] = $decode($value);
        }

        /** @var string $old */
        $old = $decode($data['old']);
        /** @var string $new */
        $new = $decode($data['new']);

        return new self($data['table'], $keys, $data['column'], $old, $new);
    }
}
