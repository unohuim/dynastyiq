<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});


Broadcast::channel('org.{orgId}', function ($user, $orgId) {
    return $user->organizations()->whereKey($orgId)->exists();
});
