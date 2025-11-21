<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\ProviderAccount;
use App\Services\Patreon\PatreonSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class PatreonSyncController extends Controller
{
    public function sync(Organization $organization, PatreonSyncService $service): JsonResponse
    {
        $this->assertUserCanManage($organization);

        $account = ProviderAccount::where('organization_id', $organization->id)
            ->where('provider', 'patreon')
            ->firstOrFail();

        $result = $service->syncProviderAccount($account);

        if (request()->wantsJson()) {
            return response()->json([
                'ok' => true,
                'account_id' => $account->id,
                'synced_at' => $account->last_synced_at,
            ] + $result);
        }

        return redirect()->route('communities.index')->with(
            'status',
            'Patreon synced (' . ($result['members_synced'] ?? 0) . ' members)'
        );
    }

    protected function assertUserCanManage(Organization $organization): void
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }

        $hasOrg = $user->organizations()->where('organizations.id', $organization->id)->exists();
        if (!$hasOrg) {
            abort(403);
        }
    }
}
