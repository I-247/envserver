<?php

namespace App\Support;

use Dotenv\Exception\InvalidFileException;
use Dotenv\Parser\Entry;
use Dotenv\Parser\Parser;
use Dotenv\Parser\Value;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Reads the contents of a .env file into a map of values.
 *
 * The parsing is delegated to vlucas/phpdotenv, the parser Laravel itself
 * uses and the one EnvFileRenderer writes for. Hand rolling a second set of
 * quoting rules here is exactly how the two sides would drift apart.
 *
 * Interpolation is deliberately not resolved: a value like ${APP_URL}/api is
 * stored verbatim, because the vault holds what was pasted, not what one
 * particular machine would have made of it.
 */
class EnvFileParser
{
    /**
     * Keys a shell and phpdotenv both accept.
     */
    private const KEY = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    /**
     * Parse the given .env contents.
     *
     * A key appearing twice keeps its last value, the way loading the file
     * would. A name without a value is read as an empty one.
     *
     * @return array<string, string>
     *
     * @throws InvalidArgumentException when the contents are not a valid .env
     */
    public function parse(#[SensitiveParameter] string $contents): array
    {
        try {
            $entries = (new Parser)->parse($contents);
        } catch (InvalidFileException $exception) {
            throw new InvalidArgumentException($this->readable($exception), previous: $exception);
        }

        $values = [];

        foreach ($entries as $entry) {
            $values[$entry->getName()] = $this->value($entry);
        }

        $this->assertKeysAreUsable(array_keys($values));

        return $values;
    }

    /**
     * Read an entry's value, treating a bare name as an empty value.
     */
    private function value(Entry $entry): string
    {
        return $entry->getValue()
            ->map(fn (Value $value) => $value->getChars())
            ->getOrElse('');
    }

    /**
     * @param  list<string>  $keys
     */
    private function assertKeysAreUsable(array $keys): void
    {
        $invalid = array_values(array_filter(
            $keys,
            fn (string $key) => preg_match(self::KEY, $key) !== 1,
        ));

        if ($invalid !== []) {
            throw new InvalidArgumentException(sprintf(
                'These names are not usable as environment variables: %s.',
                implode(', ', $invalid),
            ));
        }
    }

    /**
     * Turn the library's message into something worth showing a person.
     *
     * Its own wording ("Failed to parse dotenv file. Encountered an unexpected
     * character...") names a file that does not exist here.
     */
    private function readable(InvalidFileException $exception): string
    {
        return str_replace(
            'Failed to parse dotenv file. ',
            'This does not read as a .env file. ',
            $exception->getMessage(),
        );
    }
}
