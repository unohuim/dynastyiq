<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MembershipResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'member_profile_id' => $this->member_profile_id,
            'display_name' => $this->memberProfile?->display_name,
            'email' => $this->memberProfile?->email,
            'status' => $this->status,
            'provider' => $this->provider,
            'provider_account_id' => $this->provider_account_id,
            'provider_label' => $this->provider ? ucfirst($this->provider) : null,
            'provider_managed' => (bool) $this->provider_account_id,
            'tier' => new MembershipTierResource($this->whenLoaded('membershipTier')),
            'membership_tier_id' => $this->membership_tier_id,
            'pledge_amount_cents' => $this->pledge_amount_cents,
            'currency' => $this->currency,
            'started_at' => optional($this->started_at)->toIso8601String(),
            'ended_at' => optional($this->ended_at)->toIso8601String(),
            'synced_at' => optional($this->synced_at)->toIso8601String(),
        ];
    }
}
