<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});


Broadcast::channel('org.{orgId}', function ($user, $orgId) {
    return $user->organizations()->whereKey($orgId)->exists();
});

Broadcast::channel('admin.imports', function ($user) {
    return $user->roles()->where('slug', 'super-admin')->exists()
        || $user->roles()->where('level', '>=', 99)->exists();
});
