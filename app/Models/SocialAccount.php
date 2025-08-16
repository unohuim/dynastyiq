<?php

// app/Models/SocialAccount.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;


class SocialAccount extends Model
{
    protected $fillable = [
        'provider','provider_user_id','email','nickname','name','avatar',
        'access_token','refresh_token','expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
