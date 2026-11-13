<?php

namespace Novius\LaravelArtisanSearchReplace\Journal;

use RuntimeException;

/**
 * Writes a journal of the changes made by the search-replace, in the JSON Lines format:
 * a header line, then one line per changed value.
 */
class JournalWriter
{
    public const FORMAT = 1;

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** @var resource|null */
    private $handle;

    /**
     * @param  array<string, mixed>  $header  Information about the search-replace
     *
     * @throws RuntimeException When the file exists or cannot be created
     */
    public function __construct(public readonly string $path, array $header)
    {
        // Mode "x": never overwrite an existing journal, which may be needed for a revert
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException(file_exists($path)
                ? sprintf('The journal file [%s] already exists.', $path)
                : sprintf('Unable to create the journal file [%s].', $path));
        }
        $this->handle = $handle;

        $this->writeLine(json_encode(
            ['type' => 'header', 'format' => self::FORMAT, ...$header],
            self::JSON_FLAGS | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }

    public function write(JournalEntry $entry): void
    {
        $this->writeLine(json_encode($entry->toArray(), self::JSON_FLAGS));
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function writeLine(string $line): void
    {
        if ($this->handle === null || fwrite($this->handle, $line."\n") === false) {
            throw new RuntimeException(sprintf('Unable to write in the journal file [%s].', $this->path));
        }

        // Flushed at each line: the journal must be complete even if the process is killed
        fflush($this->handle);
    }
}
