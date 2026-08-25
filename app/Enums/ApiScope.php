<?php

namespace App\Enums;

enum ApiScope: string
{
    case ProjectsRead = 'projects:read';
    case EnvironmentRead = 'env:read';
    case EnvironmentWrite = 'env:write';
    case EnvironmentPublish = 'env:publish';

    /**
     * Get the human readable description shown on the consent screen.
     */
    public function description(): string
    {
        return match ($this) {
            self::ProjectsRead => 'See your projects and environments',
            self::EnvironmentRead => 'Read environment variables',
            self::EnvironmentWrite => 'Add and change environment variables',
            self::EnvironmentPublish => 'Publish and roll back releases',
        };
    }

    /**
     * Get every scope as Passport expects them.
     *
     * @return array<string, string>
     */
    public static function map(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $scope) => [$scope->value => $scope->description()])
            ->all();
    }
}
