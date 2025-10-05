<?php
/** @var \Illuminate\Support\Collection|\App\Models\Organization[] $communities */
$currentOrg = $communities->first(); $user = auth()->user(); // Highest
org-scoped role (by numeric level, higher = higher) $highestRole = null; if
($user && $currentOrg) { $highestRole = $user->roles()
->wherePivot('organization_id', $currentOrg->id) ->orderByDesc('level')
->first(); // expects roles table has `level` and `name` } // Admin permission =
level >= 10 $canEdit = $highestRole && (int)($highestRole->level ?? 0) >= 10; //
Connected Discord servers (from DB relation; respects eager load) $guilds =
$currentOrg ? ($currentOrg->relationLoaded('discordServers') ?
$currentOrg->discordServers : $currentOrg->discordServers()->get()) : collect();
?>
