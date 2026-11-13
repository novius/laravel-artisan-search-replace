<?php

namespace Novius\LaravelArtisanSearchReplace\Database;

class TableResult
{
    /**
     * Number of replaced values, by column.
     *
     * @var array<string, int>
     */
    public array $replacements;

    /**
     * Values that could not be processed or saved.
     *
     * @var list<string>
     */
    public array $warnings = [];

    /**
     * @param  list<string>  $columns
     */
    public function __construct(array $columns)
    {
        $this->replacements = array_fill_keys($columns, 0);
    }

    public function total(): int
    {
        return array_sum($this->replacements);
    }
}
