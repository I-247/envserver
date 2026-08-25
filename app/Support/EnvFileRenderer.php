<?php

namespace App\Support;

/**
 * Renders a map of variables as a .env file.
 *
 * The escaping rules follow vlucas/phpdotenv, the parser Laravel itself uses:
 *
 *  - Inside double quotes a backslash starts an escape sequence, and only
 *    \" \\ \$ \f \n \r \t \v are valid. Anything else is a parse error, so a
 *    stray backslash cannot be left alone.
 *  - An unescaped $ means interpolation even inside double quotes, which is
 *    how a password like p$ssw0rd would silently become something else.
 *  - Single quotes take no escapes at all, so they cannot hold a value that
 *    itself contains a single quote. Double quotes it is.
 */
class EnvFileRenderer
{
    /**
     * Values matching this can be written without quotes: no whitespace, no
     * comment marker, no quote, no backslash and no interpolation marker.
     */
    private const BARE = '/\A[^\s\\\\\'"#$]+\z/';

    /**
     * Control characters that phpdotenv understands as escape sequences.
     */
    private const ESCAPES = [
        '\\' => '\\\\',
        '"' => '\"',
        '$' => '\$',
        "\n" => '\n',
        "\r" => '\r',
        "\t" => '\t',
        "\f" => '\f',
        "\v" => '\v',
    ];

    /**
     * Render the given variables as the contents of a .env file.
     *
     * @param  array<string, string>  $variables
     */
    public function render(array $variables, ?string $header = null): string
    {
        $lines = [];

        if ($header !== null) {
            foreach (explode("\n", $header) as $line) {
                $lines[] = rtrim('# '.$line);
            }

            $lines[] = '';
        }

        foreach ($variables as $key => $value) {
            $lines[] = $key.'='.$this->value($value);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Render a single value, quoting and escaping only when needed.
     */
    private function value(string $value): string
    {
        if ($value === '' || preg_match(self::BARE, $value) === 1) {
            return $value;
        }

        return '"'.strtr($value, self::ESCAPES).'"';
    }
}
