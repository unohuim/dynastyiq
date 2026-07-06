<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\FantraxUserConnected;
use App\Models\IntegrationSecret;
use App\Models\User;
use App\Traits\HasAPITrait;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * Connects a DynastyIQ user to Fantrax using a Fantrax user secret ID.
 */
class ConnectFantraxUser
{
    use HasAPITrait;

    /**
     * Validate and persist a Fantrax user secret, then sync discovered leagues.
     *
     * @return array{league_count:int}
     */
    public function connect(User $user, string $secretId): array
    {
        $secretId = trim($secretId);

        if ($secretId === '') {
            throw new RuntimeException('Fantrax Secret ID is required.');
        }

        IntegrationSecret::updateOrCreate(
            ['user_id' => $user->id, 'provider' => 'fantrax'],
            ['secret' => $secretId, 'status' => 'connected'],
        );

        $result = $this->syncLeagues($user, $secretId);

        FantraxUserConnected::dispatch($user);

        return $result;
    }

    /**
     * Refresh discovered Fantrax leagues for a connected user.
     *
     * @return array{league_count:int}
     */
    public function syncLeagues(User $user, string $secretId): array
    {
        try {
            $response = $this->getAPIData('fantrax', 'user_leagues', [
                'userSecretId' => $secretId,
            ]);
        } catch (RequestException) {
            throw new RuntimeException('Unable to reach Fantrax. Try again.');
        }

        $leagues = $response['leagues'] ?? [];
        if (count($leagues) === 0) {
            throw new RuntimeException('Invalid Fantrax Secret ID.');
        }

        app(FantraxLeagueService::class)->upsertLeaguesForUser($user, $leagues);

        return ['league_count' => count($leagues)];
    }
}
