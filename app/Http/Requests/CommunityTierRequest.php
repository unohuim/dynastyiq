<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\MembershipTier;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CommunityTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'amount_cents' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $amount = $this->input('amount_cents');
            if ($amount === null || $amount === '') {
                return;
            }

            if ((int) $amount !== 0) {
                return;
            }

            /** @var Organization|null $organization */
            $organization = $this->route('organization');
            if (!$organization) {
                return;
            }

            /** @var MembershipTier|null $current */
            $current = $this->route('membershipTier');

            $existingFreeTier = $organization->membershipTiers()
                ->where('amount_cents', 0)
                ->when($current, fn ($query) => $query->where('id', '!=', $current->id))
                ->first();

            if ($existingFreeTier) {
                $validator->errors()->add(
                    'amount_cents',
                    'Free tier already exists, Can only have one'
                );
            }
        });
    }
}
