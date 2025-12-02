<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommunityMemberRequest extends FormRequest
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
            'email' => ['required', 'email:rfc,dns', 'max:255'],
            'membership_tier_id' => ['nullable', 'integer', 'exists:membership_tiers,id'],
            'status' => ['nullable', 'string', 'in:active,declined,former_member,deleted'],
        ];
    }
}
