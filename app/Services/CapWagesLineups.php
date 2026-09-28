<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CapWagesPlayer;
use App\Models\NhlGame;
use App\Traits\HasAPITrait;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use JsonException;
use LogicException;
use UnexpectedValueException;

/** Read current CapWages team lineups for a stored matchup without importing data. */
class CapWagesLineups
{
    use HasAPITrait;

    /**
     * Fetch each side independently; a missing lineup must not hide the other side.
     *
     * @return array<string, mixed>
     */
    public function forGame(NhlGame $game): array
    {
        return [
            'matchId' => (int) $game->nhl_game_id,
            'away' => $this->forTeam((string) $game->away_team_abbrev),
            'home' => $this->forTeam((string) $game->home_team_abbrev),
        ];
    }

    /**
     * Resolve the configured provider slug and adapt its ordered player list for display.
     *
     * @return array<string, mixed>
     */
    private function forTeam(string $abbreviation): array
    {
        $empty = ['lineup' => [], 'lastUpdated' => null, 'error' => null];
        $slug = config('apiurls.capwages.team_slugs.'.strtoupper($abbreviation));

        if (! is_string($slug) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) {
            return array_replace($empty, ['error' => 'Cap Wages team mapping is unavailable.']);
        }

        try {
            $key = config('apiurls.capwages.key');

            if (! is_string($key) || trim($key) === '') {
                throw new LogicException('CapWages credentials are not configured.');
            }

            $body = $this->getAPIData('capwages', 'lineups', ['teamSlug' => $slug], [], false);
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($payload) || ($payload['success'] ?? null) !== true
                || ($payload['data']['teamSlug'] ?? null) !== $slug
                || ! is_array($payload['data']['players'] ?? null)) {
                throw new UnexpectedValueException('Invalid CapWages lineup envelope.');
            }

            $players = $payload['data']['players'];

            foreach ($players as $player) {
                if (! is_array($player) || ! is_string($player['playerSlug'] ?? null)
                    || trim($player['playerSlug']) === '' || ! is_string($player['line'] ?? null)
                    || ! is_string($player['position'] ?? null)) {
                    throw new UnexpectedValueException('Invalid CapWages lineup player.');
                }
            }

            $profiles = CapWagesPlayer::query()
                ->whereIn('slug', array_column($players, 'playerSlug'))
                ->get(['slug', 'name', 'jersey_number'])->keyBy('slug');

            return [
                'lineup' => array_values(array_map(static function (array $player) use ($profiles): array {
                    $profile = $profiles->get($player['playerSlug']);

                    return [
                        'id' => $player['playerSlug'],
                        'player' => $profile?->name ?: $player['playerSlug'],
                        'jersey' => $profile?->jersey_number,
                        'position' => $player['position'],
                        'positionAbbreviation' => strtoupper($player['position']),
                        'line' => $player['line'],
                    ];
                }, $players)),
                'lastUpdated' => is_string($payload['meta']['lastUpdated'] ?? null)
                    ? $payload['meta']['lastUpdated'] : null,
                'error' => null,
            ];
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                return $empty;
            }

            return array_replace($empty, ['error' => 'Unable to load this team’s Cap Wages lineup. Please try again.']);
        } catch (ConnectionException | JsonException | LogicException | UnexpectedValueException $exception) {
            return array_replace($empty, ['error' => 'Unable to load this team’s Cap Wages lineup. Please try again.']);
        }
    }
}
