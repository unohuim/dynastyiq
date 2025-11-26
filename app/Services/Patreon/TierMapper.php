<?php

declare(strict_types=1);

namespace App\Services\Patreon;

use App\Models\MembershipTier;
use App\Models\ProviderAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class TierMapper
{
    public function __construct(
        protected ProviderAccount $account,
        protected string $campaignCurrency = 'USD'
    ) {
    }

    /**
     * @param array<int, array> $tiers
     * @return array<string, MembershipTier>
     */
    public function map(array $tiers): array
    {
        Log::info('starting TierMapper map()', ['tiers'=>$tiers]);
        
        $mapped = [];

        foreach ($tiers as $tier) {
            if (data_get($tier, 'type') !== 'tier') {
                continue;
            }

            $externalId = (string) data_get($tier, 'id');
            $attributes = (array) data_get($tier, 'attributes', []);
            Log::info('data_get tiers', ['attributes'=>$attributes]);
            $name = (string) ($attributes['title'] ?? '');

            if ($name === '') {
                Log::warning('Patreon tier missing required title', [
                    'provider_account_id' => $this->account->id,
                    'tier' => $tier,
                ]);

                throw new RuntimeException('Patreon tier missing required title.');
            }

            $amountCents = data_get($attributes, 'amount_cents');
            $currency = $this->campaignCurrency ?: 'USD';

            $model = $this->findExistingMappedTier($externalId)
                ?? $this->matchDiqTierByName($name, $amountCents, $currency);

            $diqOwned = $model?->provider_account_id === null;

            if (!$model) {
                $model = new MembershipTier();
                $model->forceFill([
                    'organization_id' => $this->account->organization_id,
                    'provider_account_id' => $this->account->id,
                    'provider' => $this->account->provider,
                    'external_id' => $externalId,
                ]);
            } else {
                $model->fill([
                    'provider_account_id' => $this->account->id,
                    'provider' => $this->account->provider,
                    'external_id' => $externalId,
                ]);
            }

            $model->fill([
                'name' => $diqOwned ? $model->name : $name,
                'description' => $diqOwned ? $model->description : data_get($attributes, 'description'),
                'amount_cents' => $diqOwned ? $model->amount_cents : ($amountCents !== null ? (int) $amountCents : null),
                'currency' => $diqOwned ? $model->currency : ($currency ?? 'USD'),
                'is_active' => (bool) (data_get($attributes, 'published') ?? true),
                'synced_at' => now(),
            ]);

            if (!$diqOwned) {
                $model->metadata = $tier;
            }

            $model->save();

            $mapped[$externalId] = $model;
        }

        Log::info('completed map()');
        return $mapped;
    }

    protected function findExistingMappedTier(string $externalId): ?MembershipTier
    {
        return MembershipTier::where('provider_account_id', $this->account->id)
            ->where('external_id', $externalId)
            ->first();
    }

    protected function matchDiqTierByName(string $name, ?int $amountCents, ?string $currency): ?MembershipTier
    {
        return MembershipTier::where('organization_id', $this->account->organization_id)
            ->whereNull('provider_account_id')
            ->get()
            ->first(function (MembershipTier $tier) use ($name, $amountCents, $currency) {
                if (Str::lower($tier->name) !== Str::lower($name)) {
                    return false;
                }

                if ($amountCents !== null && $tier->amount_cents !== null && (int) $tier->amount_cents !== (int) $amountCents) {
                    return false;
                }

                if ($currency && $tier->currency && Str::upper((string) $tier->currency) !== Str::upper((string) $currency)) {
                    return false;
                }

                return true;
            });
    }
}
