<?php

namespace App\Events;

use App\Models\Organization;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class OrganizationSettingsUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public Organization $organization,
        public int $actorId
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->organization->id)];
    }

    public function broadcastAs(): string
    {
        return 'org.settings.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'organization_id' => $this->organization->id,
            'settings'        => (array) ($this->organization->settings ?? []),
            'actor_id'        => $this->actorId,
            'updated_at'      => now()->toISOString(),
        ];
    }
}
