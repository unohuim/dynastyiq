<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MembershipTierResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'provider_account_id' => $this->provider_account_id,
            'provider' => $this->provider,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,
            'synced_at' => optional($this->synced_at)->toIso8601String(),
            'provider_label' => $this->provider ? ucfirst($this->provider) : null,
            'provider_managed' => (bool) $this->provider_account_id,
        ];
    }
}
