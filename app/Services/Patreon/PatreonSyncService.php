<?php

declare(strict_types=1);

namespace App\Services\Patreon;

use App\Models\MemberProfile;
use App\Models\Membership;
use App\Models\MembershipEvent;
use App\Models\MembershipTier;
use App\Models\ProviderAccount;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PatreonSyncService
{
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
        $token = $account->access_token;
        if (!$token) {
            return ['tiers' => [], 'members' => []];
        }

        try {
            $base = rtrim(config('patreon.base_url', 'https://www.patreon.com/api/oauth2/v2'), '/');
            $campaignId = $account->external_id;

            if (!$campaignId) {
                $identity = Http::withToken($token)
                    ->acceptJson()
                    ->get("{$base}/identity", ['include' => 'campaign'])
                    ->throw()
                    ->json();

                $campaignId = data_get($identity, 'data.relationships.campaign.data.id')
                    ?? data_get($identity, 'data.id');
                $account->external_id = $campaignId ? (string) $campaignId : null;
                $account->save();
            }

            if (!$campaignId) {
                return ['tiers' => [], 'members' => [], 'campaign_id' => null];
            }

            $membersResponse = Http::withToken($token)
                ->acceptJson()
                ->get("{$base}/campaigns/{$campaignId}/members", [
                    'include' => 'currently_entitled_tiers',
                    'fields[member]' => 'full_name,email,patron_status,currently_entitled_amount_cents,last_charge_date,pledge_relationship_start,currently_entitled_tiers,currency',
                    'fields[tier]' => 'title,description,amount_cents,currency,published',
                ])
                ->throw()
                ->json();

            $tiers = array_values(array_filter(Arr::wrap(data_get($membersResponse, 'included')), fn ($item) => data_get($item, 'type') === 'tier'));
            $members = array_values(array_filter(Arr::wrap(data_get($membersResponse, 'data')), fn ($item) => data_get($item, 'type') === 'member'));

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
}
