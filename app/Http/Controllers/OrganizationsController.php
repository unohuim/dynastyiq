<?php
// app/Http/Controllers/OrganizationsController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationsController extends Controller
{
    public function updateSettings(Request $request, ?Organization $organization = null)
    {
        $user = $request->user();

        // If org missing, use first membership or create a personal org
        if (! $organization?->exists) {
            $organization = $user->organizations()->first()
                ?? Organization::create([
                    'name'       => ($user->name ?: 'User') . "'s Organization",
                    'short_name' => Str::slug($user->name ?: 'user'),
                    'slug'       => Str::slug(($user->name ?: 'user') . '-' . Str::random(6)),
                    'settings'   => null,
                ]);
            $user->organizations()->syncWithoutDetaching([$organization->id => ['settings' => null]]);
        }

        // basic guard (replace with policy if you have one)
        abort_unless($user->organizations()->whereKey($organization->id)->exists(), 403);

        $data = $request->validate([
            'enabled'            => 'required|boolean',
            'name'               => 'nullable|string|min:2|max:120',
            'commissioner_tools' => 'nullable|boolean',
            'creator_tools'      => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($data, $organization, $user): void {
            if ($data['enabled'] === false) {
                $organization->settings = null;
            } else {
                $current = (array) ($organization->settings ?? []);
                $merged  = array_merge(
                    ['commissioner_tools' => false, 'creator_tools' => false],
                    $current,
                    Arr::only($data, ['commissioner_tools', 'creator_tools'])
                );
                $organization->settings = $merged;
            }

            if (array_key_exists('name', $data) && $data['name']) {
                $organization->name = $data['name'];
            }

            $organization->save();

            if ($data['enabled'] === true) {
                $this->ensureOrganizationRole($user, $organization, 'admin');
            }

            if ((bool) data_get($organization->settings, 'commissioner_tools', false)) {
                $this->ensureOrganizationRole($user, $organization, 'commissioner');
            }
        });

        return response()->json([
            'ok'           => true,
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'settings'     => $organization->settings,
        ]);
    }

    /**
     * Ensure the acting user has an organization-scoped role assignment.
     */
    private function ensureOrganizationRole(User $user, Organization $organization, string $slug): void
    {
        $role = Role::query()
            ->where('slug', $slug)
            ->where('scope', 'organization')
            ->where('is_active', true)
            ->first();

        if (! $role) {
            return;
        }

        $attributes = [
            'role_id' => $role->id,
            'user_id' => $user->id,
            'organization_id' => $organization->id,
        ];

        if (DB::table('role_user')->where($attributes)->exists()) {
            DB::table('role_user')->where($attributes)->update(['updated_at' => now()]);

            return;
        }

        DB::table('role_user')->insert($attributes + [
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
