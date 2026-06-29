<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Supported fantasy platform provider values.
 */
final class FantasyProvider
{
    public const FANTRAX = 'fantrax';
    public const YAHOO = 'yahoo';

    /**
     * Providers that can power the Leagues experience.
     *
     * @return array<int,string>
     */
    public static function leagueProviders(): array
    {
        return [
            self::FANTRAX,
            self::YAHOO,
        ];
    }
}
