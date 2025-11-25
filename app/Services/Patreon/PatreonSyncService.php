<?php

declare(strict_types=1);

namespace App\Services\Patreon;

use App\Models\MemberProfile;
use App\Models\MembershipEvent;
use App\Models\ProviderAccount;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
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

        $snapshot ??= $this->fetchRemoteSnapshot($account);
        $campaignCurrency = (string) ($snapshot['campaign_currency'] ?? 'USD');

        if (!empty($snapshot['campaign_id']) && !$account->external_id) {
            $account->external_id = (string) $snapshot['campaign_id'];
        }

        $tiers = $this->upsertTiers($account, $snapshot['tiers'] ?? [], $campaignCurrency);
        $members = $this->syncMembers($account, $snapshot['members'] ?? [], $tiers, $campaignCurrency);

        $account->forceFill([
            'status' => 'connected',
            'last_synced_at' => now(),
            'last_sync_error' => null,
        ])->save();

        return [
            'tiers_synced' => count($tiers),
            'members_synced' => $members,
        ];
    }

    public function handleWebhook(ProviderAccount $account, array $payload): void
    {
        $snapshot = [
            'tiers' => Arr::wrap(data_get($payload, 'included')),
            'members' => Arr::wrap(data_get($payload, 'data')),
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

    protected function fetchRemoteSnapshot(ProviderAccount $account): array
    {
        $account = $this->refreshAccountToken($account);

        if (!$account->access_token) {
            $meta = [
                'identity' => [],
                'campaign' => [],
            ];

            $account->forceFill(['meta' => $meta])->save();

            return [
                'tiers' => [],
                'members' => [],
                'campaign_id' => null,
                'campaign_currency' => 'USD',
            ];
        }

        try {
            [$identity, $account] = $this->callPatreon(
                $account,
                fn (string $accessToken): array => $this->client->getIdentity($accessToken)
            );

            [$campaignResponse, $account] = $this->callPatreon(
                $account,
                fn (string $accessToken): array => $this->client->getCampaigns($accessToken)
            );

            $creatorId = data_get($identity, 'data.id');

            $campaign = collect($campaignResponse['data'] ?? [])->first(
                function (array $item) use ($creatorId): bool {
                    return $creatorId
                        && data_get($item, 'relationships.creator.data.id') === (string) $creatorId;
                }
            ) ?? data_get($campaignResponse, 'data.0');

            $campaignId = $campaign ? (string) data_get($campaign, 'id') : ($account->external_id ?: null);

            $campaignWithTiers = [];
            $campaignCurrency = 'USD';
            $membersResponse = [];

            if ($campaignId) {
                [$campaignWithTiers, $account] = $this->callPatreon(
                    $account,
                    fn (string $accessToken): array => $this->client->getCampaign($accessToken, (string) $campaignId)
                );

                $campaignCurrency = (string) data_get(
                    $campaignWithTiers,
                    'data.attributes.currency',
                    'USD'
                );

                [$membersResponse, $account] = $this->callPatreon(
                    $account,
                    fn (string $accessToken): array => $this->client->getCampaignMembers(
                        $accessToken,
                        (string) $campaignId
                    )
                );
            }

            $campaignTiers = array_values(
                array_filter(
                    Arr::wrap(data_get($campaignWithTiers, 'included')),
                    fn ($item): bool => data_get($item, 'type') === 'tier'
                )
            );

            $members = array_values(
                array_filter(
                    Arr::wrap(data_get($membersResponse, 'data')),
                    fn ($item): bool => data_get($item, 'type') === 'member'
                )
            );

            $tiers = $this->mergeUniqueById(
                $campaignTiers,
                Arr::wrap(data_get($membersResponse, 'included')),
                'tier'
            );

            $identityMeta = $this->identityMeta($identity);
            $campaignMeta = $this->campaignMeta($campaign ?? [], $campaignWithTiers ?? []);

            $campaignCurrency = (string) ($campaignMeta['currency'] ?? $campaignCurrency);

            $meta = [
                'identity' => $identityMeta,
                'campaign' => $campaignMeta,
            ];

            $identityDisplayName = $this->identityDisplayName($identity);
            $campaignDisplayName = $this->campaignDisplayName($campaign ?? [], $campaignWithTiers ?? []);

            $displayName = $campaignDisplayName
                ?? $identityDisplayName
                ?? $account->display_name
                ?? 'Patreon Campaign';

            $account->forceFill([
                'external_id' => $campaignId,
                'display_name' => $displayName,
                'meta' => $meta,
            ])->save();

            return [
                'tiers' => $tiers,
                'members' => $members,
                'campaign_id' => $campaignId,
                'campaign_currency' => $campaignCurrency,
            ];
        } catch (Throwable $e) {
            $account->forceFill([
                'status' => 'offline',
                'last_sync_error' => $e->getMessage(),
            ])->save();

            Log::warning('Patreon sync failed', [
                'provider_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return ['tiers' => [], 'members' => []];
        }
    }

    protected function upsertTiers(ProviderAccount $account, array $tiers, string $campaignCurrency): array
    {
        $mapper = new TierMapper($account, $campaignCurrency);

        return $mapper->map($tiers);
    }

    protected function syncMembers(
        ProviderAccount $account,
        array $members,
        array $tierMap,
        string $campaignCurrency
    ): int {
        $count = 0;

        foreach ($members as $member) {
            if (data_get($member, 'type') !== 'member') {
                continue;
            }

            $count++;

            $externalId = (string) data_get($member, 'id');
            $attributes = (array) data_get($member, 'attributes', []);

            $email = data_get($attributes, 'email');
            $name = data_get($attributes, 'full_name')
                ?: (string) data_get($attributes, 'patron_status', 'Member');

            $pledge = data_get($attributes, 'currently_entitled_amount_cents')
                ?? data_get($attributes, 'lifetime_support_cents');

            $status = $this->mapStatus((string) data_get($attributes, 'patron_status'));

            $tierId = Arr::first((array) data_get($member, 'relationships.currently_entitled_tiers.data'));
            $membershipTier = $tierId ? ($tierMap[(string) data_get($tierId, 'id')] ?? null) : null;

            $profile = $this->resolveMemberProfile($account, $externalId, $email, $name, null);

            $endedAt = null;

            if ($status !== 'active') {
                $endedAt = data_get($attributes, 'pledge_relationship_start')
                    ?? now()->toIsoString();
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

    /**
     * @return array{0: array, 1: ProviderAccount}
     */
    protected function callPatreon(ProviderAccount $account, callable $callback): array
    {
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

    protected function mergeUniqueById(array $existing, array $items, string $type): array
    {
        $map = collect($existing)
            ->keyBy(fn ($item) => data_get($item, 'id'))
            ->all();

        foreach ($items as $item) {
            if (data_get($item, 'type') !== $type) {
                continue;
            }

            $map[(string) data_get($item, 'id')] = $item;
        }

        return array_values($map);
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

    protected function identityMeta(array $identity): array
    {
        return array_filter([
            'id' => data_get($identity, 'data.id'),
            'full_name' => data_get($identity, 'data.attributes.full_name'),
            'email' => data_get($identity, 'data.attributes.email'),
            'vanity' => data_get($identity, 'data.attributes.vanity'),
            'image_url' => data_get($identity, 'data.attributes.image_url')
                ?? data_get($identity, 'data.attributes.thumb_url'),
        ]);
    }

    protected function campaignMeta(array $campaign, array $campaignWithTiers): array
    {
        $attributes = (array) data_get($campaignWithTiers, 'data.attributes', []);
        $fallbackAttributes = (array) data_get($campaign, 'attributes', []);

        return array_filter([
            'id' => data_get($campaignWithTiers, 'data.id') ?? data_get($campaign, 'id'),
            'summary' => data_get($attributes, 'summary') ?? data_get($fallbackAttributes, 'summary'),
            'image_url' => data_get($attributes, 'avatar_photo_url')
                ?? data_get($attributes, 'image_small_url')
                ?? data_get($attributes, 'image_url')
                ?? data_get($fallbackAttributes, 'avatar_photo_url')
                ?? data_get($fallbackAttributes, 'image_small_url')
                ?? data_get($fallbackAttributes, 'image_url'),
            'currency' => data_get($attributes, 'currency') ?? data_get($fallbackAttributes, 'currency'),
        ]);
    }

    protected function identityDisplayName(array $identity): string
    {
        $fullName = (string) data_get($identity, 'data.attributes.full_name');
        if ($fullName !== '') {
            return $fullName;
        }

        $first = (string) data_get($identity, 'data.attributes.first_name');
        $last = (string) data_get($identity, 'data.attributes.last_name');

        if ($first !== '' || $last !== '') {
            return trim($first . ' ' . $last);
        }

        $vanity = (string) data_get($identity, 'data.attributes.vanity');
        if ($vanity !== '') {
            return $vanity;
        }

        return 'Patreon Creator';
    }

    protected function campaignDisplayName(array $campaign, array $campaignWithTiers): ?string
    {
        $attributes = (array) data_get($campaignWithTiers, 'data.attributes', []);
        $fallbackAttributes = (array) data_get($campaign, 'attributes', []);

        return data_get($attributes, 'summary')
            ?? data_get($attributes, 'one_liner')
            ?? data_get($attributes, 'pledge_url')
            ?? data_get($fallbackAttributes, 'summary')
            ?? data_get($fallbackAttributes, 'one_liner')
            ?? data_get($fallbackAttributes, 'pledge_url');
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
