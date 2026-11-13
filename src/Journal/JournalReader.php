<?php

namespace Novius\LaravelArtisanSearchReplace\Journal;

use Generator;
use JsonException;
use Novius\LaravelArtisanSearchReplace\Exceptions\InvalidJournalException;

class JournalReader
{
    /**
     * @var array<string, mixed>
     */
    public readonly array $header;

    /**
     * @throws InvalidJournalException
     */
    public function __construct(public readonly string $path)
    {
        $handle = $this->open();
        $line = fgets($handle);
        fclose($handle);

        $header = $line === false ? null : json_decode($line, true);
        if (! is_array($header)
            || ($header['type'] ?? null) !== 'header'
            || ($header['format'] ?? null) !== JournalWriter::FORMAT
        ) {
            throw new InvalidJournalException(sprintf('The file [%s] is not a valid search-replace journal.', $path));
        }

        $this->header = array_combine(array_map(strval(...), array_keys($header)), $header);
    }

    /**
     * @return Generator<int, JournalEntry>
     *
     * @throws InvalidJournalException
     */
    public function entries(): Generator
    {
        $handle = $this->open();

        try {
            fgets($handle); // Header
            $lineNumber = 1;
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                if (trim($line) === '') {
                    continue;
                }

                try {
                    $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (! is_array($data)) {
                        throw new InvalidJournalException('Malformed journal entry.');
                    }
                    yield JournalEntry::fromArray($data);
                } catch (JsonException|InvalidJournalException $e) {
                    throw new InvalidJournalException(sprintf('Invalid entry at line %d of the journal [%s]: %s', $lineNumber, $this->path, $e->getMessage()), previous: $e);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private function open()
    {
        $handle = is_file($this->path) ? @fopen($this->path, 'rb') : false;
        if ($handle === false) {
            throw new InvalidJournalException(sprintf('Unable to read the journal file [%s].', $this->path));
        }

        return $handle;
    }
}
