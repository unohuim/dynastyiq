<?php

declare(strict_types=1);

namespace App\Services\Patreon;

use App\Models\MemberProfile;
use App\Models\Membership;
use App\Models\MembershipEvent;
use App\Models\MembershipTier;
use App\Models\ProviderAccount;
use Illuminate\Support\Carbon;

class MembershipSyncService
{
    public function sync(
        ProviderAccount $account,
        MemberProfile $profile,
        string $providerMemberId,
        ?MembershipTier $tier,
        ?int $pledgeAmountCents,
        string $currency,
        string $status,
        ?string $startedAt,
        ?string $endedAt,
        array $metadata
    ): Membership {
        $membership = Membership::where('provider_account_id', $account->id)
            ->where('provider_member_id', $providerMemberId)
            ->first();

        if (!$membership) {
            $membership = Membership::where('organization_id', $account->organization_id)
                ->where('member_profile_id', $profile->id)
                ->orderByDesc('synced_at')
                ->first();
        }

        if (!$membership) {
            $membership = new Membership([
                'provider_account_id' => $account->id,
                'provider_member_id' => $providerMemberId,
            ]);
        }

        $original = [
            'membership_tier_id' => $membership->membership_tier_id,
            'pledge_amount_cents' => $membership->pledge_amount_cents,
            'status' => $membership->status,
        ];

        $membership->fill([
            'organization_id' => $account->organization_id,
            'member_profile_id' => $profile->id,
            'membership_tier_id' => $tier?->id,
            'provider' => $account->provider,
            'provider_account_id' => $account->id,
            'provider_member_id' => $providerMemberId,
            'status' => $status,
            'pledge_amount_cents' => $pledgeAmountCents,
            'currency' => $currency,
            'started_at' => $startedAt ? Carbon::parse($startedAt) : null,
            'ended_at' => $endedAt ? Carbon::parse($endedAt) : null,
            'synced_at' => now(),
            'metadata' => $metadata,
        ]);

        $membership->save();

        $this->logChanges($membership, $original, $metadata);

        return $membership;
    }

    protected function logChanges(Membership $membership, array $original, array $metadata): void
    {
        $this->maybeLogEvent($membership, 'membership_tier_id', 'tier.changed', $original, $metadata);
        $this->maybeLogEvent($membership, 'pledge_amount_cents', 'pledge.changed', $original, $metadata);
        $this->maybeLogEvent($membership, 'status', 'status.changed', $original, $metadata);
    }

    protected function maybeLogEvent(
        Membership $membership,
        string $field,
        string $eventType,
        array $original,
        array $metadata
    ): void {
        if ($membership->$field === ($original[$field] ?? null)) {
            return;
        }

        MembershipEvent::create([
            'membership_id' => $membership->id,
            'provider_account_id' => $membership->provider_account_id,
            'event_type' => $eventType,
            'payload' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
