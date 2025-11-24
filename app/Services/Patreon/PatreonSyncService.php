<?php

declare(strict_types=1);

namespace App\Services\Patreon;

use App\Models\MemberProfile;
use App\Models\Membership;
use App\Models\MembershipEvent;
use App\Models\MembershipTier;
use App\Models\ProviderAccount;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PatreonSyncService
{
    public function __construct(protected PatreonClient $client)
    {
    }

    public function syncProviderAccount(ProviderAccount $account, ?array $snapshot = null): array
    {
        $account->refresh();

        $snapshot ??= $this->fetchRemoteSnapshot($account);

        if (!empty($snapshot['campaign_id']) && !$account->external_id) {
            $account->external_id = (string) $snapshot['campaign_id'];
        }

        $tiers = $this->upsertTiers($account, $snapshot['tiers'] ?? []);
        $members = $this->upsertMembers($account, $snapshot['members'] ?? [], $tiers);

        $account->forceFill([
            'status'        => 'connected',
            'last_synced_at'=> now(),
            'last_sync_error' => null,
        ])->save();

        return [
            'tiers_synced'   => count($tiers),
            'members_synced' => $members,
        ];
    }

    public function handleWebhook(ProviderAccount $account, array $payload): void
    {
        $snapshot = [
            'tiers'   => Arr::wrap(data_get($payload, 'included')),
            'members' => Arr::wrap(data_get($payload, 'data')),
        ];

        $result = $this->syncProviderAccount($account, $snapshot);

        $account->forceFill([
            'last_webhook_at' => now(),
        ])->save();

        MembershipEvent::create([
            'provider_account_id' => $account->id,
            'event_type'          => 'patreon.webhook',
            'payload'             => $payload,
            'occurred_at'         => now(),
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
            return ['tiers' => [], 'members' => []];
        }

        try {
            $base = rtrim(config('patreon.base_url', 'https://www.patreon.com/api/oauth2/v2'), '/');
            $campaignId = $account->external_id;

            if (!$campaignId) {
                [$identity, $account] = $this->callPatreon($account, function (string $accessToken) use ($base) {
                    return Http::withToken($accessToken)
                        ->acceptJson()
                        ->get("{$base}/identity", ['include' => 'campaign'])
                        ->throw()
                        ->json();
                });

                $campaignId = data_get($identity, 'data.relationships.campaign.data.id');

                if (!$campaignId) {
                    [$campaignResponse, $account] = $this->callPatreon($account, function (string $accessToken) use ($base) {
                        return Http::withToken($accessToken)
                            ->acceptJson()
                            ->get("{$base}/campaigns", [
                                'include' => 'creator',
                                'fields[campaign]' => 'name,creation_name,avatar_photo_url,image_small_url,image_url',
                                'page[count]' => 1,
                            ])
                            ->throw()
                            ->json();
                    });

                    $campaignId = data_get($campaignResponse, 'data.0.id');
                }

                $campaignId ??= data_get($identity, 'data.id');

                $account->external_id = $campaignId ? (string) $campaignId : null;
                $account->save();
            }

            if (!$campaignId) {
                return ['tiers' => [], 'members' => [], 'campaign_id' => null];
            }

            [$members, $tiers, $account] = $this->fetchMembers($account, (string) $campaignId, $base);

            return [
                'tiers' => $tiers,
                'members' => $members,
                'campaign_id' => $campaignId,
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

    protected function upsertTiers(ProviderAccount $account, array $tiers): array
    {
        $map = [];
        foreach ($tiers as $tier) {
            if (data_get($tier, 'type') !== 'tier') {
                continue;
            }

            $externalId = (string) data_get($tier, 'id');
            $attributes = data_get($tier, 'attributes', []);

            $model = MembershipTier::updateOrCreate(
                [
                    'provider_account_id' => $account->id,
                    'external_id'         => $externalId,
                ],
                [
                    'organization_id' => $account->organization_id,
                    'provider'        => $account->provider,
                    'name'            => data_get($attributes, 'title', 'Tier'),
                    'description'     => data_get($attributes, 'description'),
                    'amount_cents'    => data_get($attributes, 'amount_cents'),
                    'currency'        => data_get($attributes, 'currency', 'USD'),
                    'is_active'       => (bool) data_get($attributes, 'published', true),
                    'synced_at'       => now(),
                    'metadata'        => $tier,
                ]
            );

            $map[$externalId] = $model;
        }

        return $map;
    }

    protected function upsertMembers(ProviderAccount $account, array $members, array $tierMap): int
    {
        $count = 0;

        foreach ($members as $member) {
            if (data_get($member, 'type') !== 'member') {
                continue;
            }

            $count++;
            $externalId = (string) data_get($member, 'id');
            $attributes = data_get($member, 'attributes', []);
            $email = data_get($attributes, 'email');
            $name  = data_get($attributes, 'full_name', data_get($attributes, 'patron_status', 'Member'));
            $pledge = data_get($attributes, 'currently_entitled_amount_cents');
            $status = data_get($attributes, 'patron_status', 'active');
            $tierId = Arr::first(
                (array) data_get($member, 'relationships.currently_entitled_tiers.data'),
                null,
                []
            );

            $profile = MemberProfile::firstOrCreate(
                [
                    'organization_id' => $account->organization_id,
                    'email'           => $email,
                ],
                [
                    'display_name' => $name,
                    'external_ids' => ['patreon' => $externalId],
                ]
            );

            $membership = Membership::updateOrCreate(
                [
                    'provider_account_id' => $account->id,
                    'provider_member_id'  => $externalId,
                ],
                [
                    'organization_id'   => $account->organization_id,
                    'member_profile_id' => $profile->id,
                    'membership_tier_id'=> $tierId ? ($tierMap[(string) data_get($tierId, 'id')] ?? null)?->id : null,
                    'provider'          => $account->provider,
                    'status'            => $status ?: 'active',
                    'pledge_amount_cents'=> $pledge,
                    'currency'          => data_get($attributes, 'currency', 'USD'),
                    'started_at'        => data_get($attributes, 'pledge_relationship_start')
                        ? Carbon::parse(data_get($attributes, 'pledge_relationship_start'))
                        : null,
                    'ended_at'          => data_get($attributes, 'last_charge_date') && $status === 'deleted'
                        ? Carbon::parse(data_get($attributes, 'last_charge_date'))
                        : null,
                    'synced_at'         => now(),
                    'metadata'          => $member,
                ]
            );

            MembershipEvent::create([
                'membership_id'       => $membership->id,
                'provider_account_id' => $account->id,
                'event_type'          => 'patreon.sync',
                'payload'             => $member,
                'occurred_at'         => now(),
            ]);
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

        $meta = (array) ($account->meta ?? []);
        data_set($meta, 'tokens.access_token', $tokens['access_token']);
        data_set($meta, 'tokens.refresh_token', $tokens['refresh_token']);

        $account->forceFill([
            'access_token' => $tokens['access_token'] ?? $account->access_token,
            'refresh_token' => $tokens['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            'meta' => $meta,
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

    /**
     * @return array{0: array<int, mixed>, 1: array<int, mixed>, 2: ProviderAccount}
     */
    protected function fetchMembers(ProviderAccount $account, string $campaignId, string $base): array
    {
        $members = [];
        $tiers = [];
        $nextUrl = "{$base}/campaigns/{$campaignId}/members";
        $params = [
            'include' => 'currently_entitled_tiers',
            'fields[member]' => 'full_name,email,patron_status,currently_entitled_amount_cents,last_charge_date,pledge_relationship_start,currently_entitled_tiers,currency',
            'fields[tier]' => 'title,description,amount_cents,currency,published',
        ];

        while ($nextUrl) {
            [$response, $account] = $this->callPatreon($account, function (string $accessToken) use (&$params, $nextUrl) {
                $request = Http::withToken($accessToken)->acceptJson();

                if ($params) {
                    return $request->get($nextUrl, $params)->throw()->json();
                }

                return $request->get($nextUrl)->throw()->json();
            });

            $tiers = $this->mergeUniqueById($tiers, Arr::wrap(data_get($response, 'included')), 'tier');
            $members = array_merge(
                $members,
                array_values(array_filter(Arr::wrap(data_get($response, 'data')), fn ($item) => data_get($item, 'type') === 'member'))
            );

            $nextUrl = data_get($response, 'links.next');
            $params = []; // links.next already contains query params
        }

        return [$members, $tiers, $account];
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
}
