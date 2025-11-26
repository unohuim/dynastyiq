<?php

declare(strict_types=1);

namespace App\Services\Patreon;

use App\Models\MemberProfile;
use App\Models\MembershipEvent;
use App\Models\ProviderAccount;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PatreonSyncService
{
    public function __construct(
        protected PatreonClient $client,
        protected MembershipSyncService $membershipSync
    ) {
    }

    public function syncProviderAccount(ProviderAccount $account, ?array $snapshot = null): array
    {
        $account->refresh();

        $result = [
            'tiers_synced' => 0,
            'members_synced' => 0,
        ];

        try {
            $account = $this->refreshAccountToken($account);

            if (!$account->access_token) {
                throw new RuntimeException('Missing Patreon access token.');
            }

            $metadata = $this->ensureMetadata($account);
            $account = $metadata['account'];
            $campaignId = $metadata['campaign_id'];
            $campaignCurrency = $metadata['campaign_currency'];

            if (!$campaignId) {
                throw new RuntimeException('No Patreon campaign available for sync.');
            }

            $tiersResponse = $snapshot['tiers'] ?? null;
            $tiersPayload = $tiersResponse !== null ? Arr::wrap($tiersResponse) : $this->fetchTiers($account, $campaignId);

            $tiers = $this->syncTiers($account, $tiersPayload, $campaignCurrency);
            $result['tiers_synced'] = count($tiers);

            $membersPayload = $this->prepareMemberPayload($snapshot, $account, $campaignId);

            $result['members_synced'] = $this->syncMembers(
                $account,
                $membersPayload['members'],
                $membersPayload['included'],
                $tiers,
                $campaignCurrency
            );

            $account->forceFill([
                'status' => 'connected',
                'last_synced_at' => now(),
                'last_sync_error' => null,
            ])->save();
        } catch (Throwable $e) {
            $account->forceFill([
                'status' => 'offline',
                'last_sync_error' => $e->getMessage(),
            ])->save();

            Log::warning('Patreon sync failed', [
                'provider_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    public function handleWebhook(ProviderAccount $account, array $payload): void
    {
        $snapshot = [
            'tiers' => Arr::wrap(data_get($payload, 'included')),
            'members' => Arr::wrap(data_get($payload, 'data')),
            'included' => Arr::wrap(data_get($payload, 'included')),
        ];

        $result = $this->syncProviderAccount($account, $snapshot);

        $account->forceFill([
            'last_webhook_at' => now(),
        ])->save();

        MembershipEvent::create([
            'provider_account_id' => $account->id,
            'event_type' => 'patreon.webhook',
            'payload' => $payload,
            'occurred_at' => now(),
        ]);

        Log::info('Patreon webhook processed', [
            'provider_account_id' => $account->id,
            'result' => $result,
        ]);
    }

    protected function ensureMetadata(ProviderAccount $account): array
    {
        [$identity, $account] = $this->callPatreon(
            $account,
            fn (string $accessToken): array => $this->client->getIdentity($accessToken)
        );

        [$campaignsResponse, $account] = $this->callPatreon(
            $account,
            fn (string $accessToken): array => $this->client->getCampaigns($accessToken)
        );

        $creatorId = (string) data_get($identity, 'data.id', '');

        $campaign = collect($campaignsResponse['data'] ?? [])
            ->first(function (array $item) use ($creatorId): bool {
                return $creatorId !== ''
                    && data_get($item, 'relationships.creator.data.id') === $creatorId;
            }) ?? ($campaignsResponse['data'][0] ?? null);

        $fromApi = data_get($campaign, 'id');
        $campaignId = $fromApi !== null && $fromApi !== ''
            ? (string) $fromApi
            : null;

        $campaignDetails = [];

        if ($campaignId) {
            [$campaignDetails, $account] = $this->callPatreon(
                $account,
                fn (string $accessToken): array => $this->client->getCampaign($accessToken, $campaignId)
            );
        }

        $campaignCurrency = (string) data_get($campaignDetails, 'data.attributes.currency')
            ?: (string) data_get($campaign, 'attributes.currency', 'USD');

        $identityMeta = $identity ?? [];
        $campaignMeta = $campaignDetails ?: ($campaign ?? []);

        $displayName = $this->displayNameFromMetadata($identityMeta, $campaignMeta);

        $account->forceFill([
            'external_id' => $campaignId,
            'display_name' => $displayName,
            'meta' => [
                'identity' => $identityMeta,
                'campaign' => $campaignMeta,
            ],
            'status' => 'connected',
            'last_sync_error' => null,
        ])->save();

        return [
            'account' => $account->refresh(),
            'campaign_id' => $campaignId,
            'campaign_currency' => $campaignCurrency !== '' ? $campaignCurrency : 'USD',
        ];
    }

    protected function fetchTiers(ProviderAccount $account, string $campaignId): array
    {
        [$tiersResponse, $account] = $this->callPatreon(
            $account,
            fn (string $accessToken): array => $this->client->getCampaignTiers($accessToken, $campaignId)
        );

        return Arr::wrap($tiersResponse['data'] ?? []);
    }

    protected function syncTiers(ProviderAccount $account, array $tiers, string $campaignCurrency): array
    {
        $mapper = new TierMapper($account, $campaignCurrency);

        return $mapper->map($tiers);
    }

    protected function prepareMemberPayload(?array $snapshot, ProviderAccount $account, string $campaignId): array
    {
        if ($snapshot !== null) {
            return [
                'members' => Arr::wrap($snapshot['members'] ?? []),
                'included' => Arr::wrap($snapshot['included'] ?? $snapshot['tiers'] ?? []),
            ];
        }

        [$membersResponse, $account] = $this->callPatreon(
            $account,
            fn (string $accessToken): array => $this->client->getCampaignMembers($accessToken, $campaignId)
        );

        return [
            'members' => Arr::wrap($membersResponse['data'] ?? []),
            'included' => Arr::wrap($membersResponse['included'] ?? []),
        ];
    }

    protected function syncMembers(
        ProviderAccount $account,
        array $members,
        array $included,
        array $tierMap,
        string $campaignCurrency
    ): int {
        if (empty($tierMap)) {
            Log::warning('Patreon member sync skipped due to missing tiers', [
                'provider_account_id' => $account->id,
            ]);

            return 0;
        }

        $count = 0;

        foreach ($members as $member) {
            if (data_get($member, 'type') !== 'member') {
                continue;
            }

            $attributes = (array) data_get($member, 'attributes', []);

            if (empty($attributes)) {
                Log::warning('Patreon member skipped due to missing attributes', [
                    'provider_account_id' => $account->id,
                    'member' => $member,
                ]);

                continue;
            }

            $externalId = (string) data_get($member, 'id', '');

            if ($externalId === '') {
                Log::warning('Patreon member skipped due to missing id', [
                    'provider_account_id' => $account->id,
                    'member' => $member,
                ]);

                continue;
            }

            $tierRelationships = Arr::wrap(data_get($member, 'relationships.currently_entitled_tiers.data', []));
            $tierId = collect($tierRelationships)
                ->map(fn ($item): string => (string) data_get($item, 'id', ''))
                ->first(fn (string $id): bool => $id !== '' && isset($tierMap[$id]));

            if (!$tierId) {
                Log::warning('Patreon member skipped due to missing tier mapping', [
                    'provider_account_id' => $account->id,
                    'member_id' => $externalId,
                ]);

                continue;
            }

            $membershipTier = $tierMap[$tierId] ?? null;

            if (!$membershipTier) {
                continue;
            }

            $email = data_get($attributes, 'email');
            $name = data_get($attributes, 'full_name');
            $status = $this->mapStatus((string) data_get($attributes, 'patron_status'));
            $pledge = data_get($attributes, 'currently_entitled_amount_cents')
                ?? data_get($attributes, 'lifetime_support_cents');

            $profile = $this->resolveMemberProfile($account, $externalId, $email, $name ?: $status, null);

            $endedAt = null;

            if ($status !== 'active') {
                $endedAt = data_get($attributes, 'pledge_relationship_start')
                    ?: now()->toIsoString();
            }

            $this->membershipSync->sync(
                $account,
                $profile,
                $externalId,
                $membershipTier,
                $pledge ? (int) $pledge : null,
                $campaignCurrency,
                $status,
                data_get($attributes, 'pledge_relationship_start'),
                $endedAt,
                $member
            );

            $count++;
        }

        return $count;
    }

    protected function refreshAccountToken(ProviderAccount $account, bool $force = false): ProviderAccount
    {
        $expiresAt = $account->token_expires_at;
        $shouldRefresh = $force;

        if (!$shouldRefresh && $expiresAt) {
            $shouldRefresh = now()->greaterThanOrEqualTo($expiresAt->subMinutes(5));
        }

        if (!$shouldRefresh || !$account->refresh_token) {
            return $account;
        }

        $tokens = $this->client->refreshToken($account->refresh_token);

        $account->forceFill([
            'access_token' => $tokens['access_token'] ?? $account->access_token,
            'refresh_token' => $tokens['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
        ])->save();

        return $account->refresh();
    }

    protected function callPatreon(ProviderAccount $account, callable $callback): array
    {
        // Log the API URL before executing the request
        Log::info('Patreon API request', [
            'provider_account_id' => $account->external_id,
            'url' => $this->client->getLastPreparedUrl(), // <-- new
        ]);
        
        try {
            return [$callback($account->access_token), $account];
        } catch (RequestException $e) {
            if ($e->response && $e->response->status() === 401 && $account->refresh_token) {
                $account = $this->refreshAccountToken($account, true);

                return [$callback($account->access_token), $account];
            }

            throw $e;
        }
    }
    

    protected function resolveMemberProfile(
        ProviderAccount $account,
        string $providerMemberId,
        ?string $email,
        ?string $displayName,
        ?string $avatar
    ): MemberProfile {
        $query = MemberProfile::where('organization_id', $account->organization_id);

        $profile = $query
            ->where("external_ids->{$account->provider}", $providerMemberId)
            ->first();

        if (!$profile && $email) {
            $profile = MemberProfile::where('organization_id', $account->organization_id)
                ->where('email', $email)
                ->first();

            if ($profile) {
                $profile->attachExternalId($account->provider, $providerMemberId, false);
            }
        }

        if (!$profile) {
            $profile = new MemberProfile([
                'organization_id' => $account->organization_id,
                'email' => $email,
                'display_name' => $displayName,
                'external_ids' => [$account->provider => $providerMemberId],
            ]);
        }

        if (!$profile->display_name && $displayName) {
            $profile->display_name = $displayName;
        }

        if (!$profile->avatar_url && $avatar) {
            $profile->avatar_url = $avatar;
        }

        if (!$profile->getExternalId($account->provider)) {
            $profile->attachExternalId($account->provider, $providerMemberId, false);
        }

        $profile->save();

        return $profile;
    }

    protected function displayNameFromMetadata(array $identityMeta, array $campaignMeta): string
    {
        $campaignName = (string) data_get($campaignMeta, 'data.attributes.summary')
            ?: (string) data_get($campaignMeta, 'attributes.summary', '');

        if ($campaignName !== '') {
            return $campaignName;
        }

        $identityName = (string) data_get($identityMeta, 'data.attributes.full_name')
            ?: (string) data_get($identityMeta, 'data.attributes.vanity', '');

        if ($identityName !== '') {
            return $identityName;
        }

        return 'Patreon Campaign';
    }

    protected function mapStatus(?string $patronStatus): string
    {
        return match ($patronStatus) {
            'declined_patron' => 'declined',
            'former_patron' => 'former_member',
            'deleted' => 'deleted',
            default => 'active',
        };
    }
}
