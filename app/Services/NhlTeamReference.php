<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlTeam;

/**
 * Maintains and resolves NHL team reference data.
 */
class NhlTeamReference
{
    /**
     * Upsert one NHL team row from a provider payload.
     *
     * @param array<string,mixed> $payload
     */
    public function upsertFromStatsPayload(array $payload): ?NhlTeam
    {
        $abbrev = $this->stringValue(
            $payload['triCode']
            ?? $payload['rawTricode']
            ?? $payload['abbrev']
            ?? $payload['teamAbbrev']
            ?? null
        );

        if ($abbrev === null) {
            return null;
        }

        return NhlTeam::updateOrCreate(
            ['abbrev' => mb_strtoupper($abbrev)],
            [
                'nhl_id' => $this->nullableInt($payload['id'] ?? $payload['teamId'] ?? null),
                'full_name' => $this->stringValue($payload['fullName'] ?? $payload['name'] ?? null),
                'common_name' => $this->stringValue($payload['teamName'] ?? $payload['commonName'] ?? null),
                'place_name' => $this->stringValue($payload['placeName'] ?? $payload['locationName'] ?? null),
                'raw_payload' => $payload,
            ],
        );
    }

    /**
     * Upsert a team reference from an NHL player landing payload.
     *
     * @param array<string,mixed> $payload
     */
    public function upsertFromPlayerPayload(array $payload): ?NhlTeam
    {
        $nhlId = $this->nullableInt($payload['currentTeamId'] ?? null);
        $abbrev = $this->stringValue($payload['currentTeamAbbrev'] ?? null);

        if ($abbrev === null) {
            return null;
        }

        $team = NhlTeam::query()->firstOrNew(['abbrev' => mb_strtoupper($abbrev)]);

        if ($nhlId === null && ! $team->exists) {
            return null;
        }

        if ($nhlId !== null) {
            $team->nhl_id = $nhlId;
        }

        $team->fill([
            'full_name' => $this->localizedDefault($payload['fullTeamName'] ?? null),
            'common_name' => $this->localizedDefault($payload['teamCommonName'] ?? null),
            'place_name' => $this->localizedDefault($payload['teamPlaceNameWithPreposition'] ?? null),
            'raw_payload' => [
                'currentTeamId' => $payload['currentTeamId'] ?? null,
                'currentTeamAbbrev' => $payload['currentTeamAbbrev'] ?? null,
                'fullTeamName' => $payload['fullTeamName'] ?? null,
                'teamCommonName' => $payload['teamCommonName'] ?? null,
                'teamPlaceNameWithPreposition' => $payload['teamPlaceNameWithPreposition'] ?? null,
            ],
        ]);
        $team->save();

        return $team;
    }

    /**
     * Normalize an abbrev, full name, common name, or place name to an NHL abbrev.
     */
    public function normalizeToAbbrev(?string $team): ?string
    {
        $team = $this->stringValue($team);

        if ($team === null) {
            return null;
        }

        $upper = mb_strtoupper($team);
        $existing = NhlTeam::query()
            ->whereRaw('UPPER(abbrev) = ?', [$upper])
            ->orWhereRaw('UPPER(full_name) = ?', [$upper])
            ->orWhereRaw('UPPER(common_name) = ?', [$upper])
            ->orWhereRaw('UPPER(place_name) = ?', [$upper])
            ->first();

        if ($existing !== null) {
            return $existing->abbrev;
        }

        return mb_strlen($team) <= 4 ? $upper : $team;
    }

    /**
     * Resolve an NHL team id from an abbreviation or team name.
     */
    public function idForAbbrev(?string $team): ?int
    {
        $abbrev = $this->normalizeToAbbrev($team);

        if ($abbrev === null) {
            return null;
        }

        return NhlTeam::query()
            ->whereRaw('UPPER(abbrev) = ?', [mb_strtoupper($abbrev)])
            ->value('nhl_id');
    }

    /**
     * Read a localized NHL API value.
     */
    private function localizedDefault(mixed $value): ?string
    {
        if (is_array($value)) {
            return $this->stringValue($value['default'] ?? null);
        }

        return $this->stringValue($value);
    }

    /**
     * Normalize provider scalar strings.
     */
    private function stringValue(mixed $value): ?string
    {
        if (is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Convert provider numeric fields to integers.
     */
    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
