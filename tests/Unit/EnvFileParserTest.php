<?php

use App\Support\EnvFileParser;
use App\Support\EnvFileRenderer;

function parseEnv(string $contents): array
{
    return (new EnvFileParser)->parse($contents);
}

it('reads the shapes a .env file can take', function (string $contents, array $expected) {
    expect(parseEnv($contents))->toBe($expected);
})->with([
    'bare' => ["APP_ENV=production\n", ['APP_ENV' => 'production']],
    'empty value' => ["EMPTY=\n", ['EMPTY' => '']],
    'name without a value' => ["EMPTY\n", ['EMPTY' => '']],
    'double quoted' => ['KEY="two words"', ['KEY' => 'two words']],
    'single quoted' => ["KEY='two words'", ['KEY' => 'two words']],
    'escaped dollar' => ['KEY="p\$ssw0rd"', ['KEY' => 'p$ssw0rd']],
    'escaped quote' => ['KEY="say \"hi\""', ['KEY' => 'say "hi"']],
    'escaped backslash' => ['KEY="C:\\\\path"', ['KEY' => 'C:\path']],
    'newline escape' => ['KEY="a\nb"', ['KEY' => "a\nb"]],
    'comment stripped' => ['KEY=value # trailing', ['KEY' => 'value']],
    'hash inside quotes' => ['KEY="value#kept"', ['KEY' => 'value#kept']],
    'export prefix' => ['export KEY=value', ['KEY' => 'value']],
    'comment line' => ["# just a comment\nKEY=v", ['KEY' => 'v']],
    'blank lines' => ["\n\nKEY=v\n\n", ['KEY' => 'v']],
    'equals in value' => ['KEY=a=b=c', ['KEY' => 'a=b=c']],
]);

it('survives a round trip through the renderer', function (string $value) {
    $rendered = (new EnvFileRenderer)->render(['SECRET' => $value]);

    expect(parseEnv($rendered))->toBe(['SECRET' => $value]);
})->with([
    'production',
    '',
    'two words',
    'value#notacomment',
    'p$ssw0rd',
    'say "hi"',
    "it's fine",
    'C:\path\to',
    "line1\nline2",
    "a\tb",
    'wachtwoord-mét-ünicode-🔐',
    'postgres://user:p@ss w0rd!#x@host:5432/db?sslmode=require',
    '{"key":"value","n":1}',
    "-----BEGIN RSA PRIVATE KEY-----\nMIIEow==\n-----END RSA PRIVATE KEY-----",
]);

it('keeps the last value when a key appears twice', function () {
    expect(parseEnv("KEY=first\nKEY=second\n"))->toBe(['KEY' => 'second']);
});

it('leaves interpolation alone instead of resolving it', function () {
    expect(parseEnv("APP_URL=https://envserver.test\nAPI_URL=\${APP_URL}/api\n"))
        ->toBe(['APP_URL' => 'https://envserver.test', 'API_URL' => '${APP_URL}/api']);
});

it('rejects contents that are not a .env file', function () {
    parseEnv("this is just a sentence\n");
})->throws(InvalidArgumentException::class, 'This does not read as a .env file.');

it('rejects a name a shell would not accept', function () {
    parseEnv("9LIVES=cat\n");
})->throws(InvalidArgumentException::class);
