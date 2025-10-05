<?php
// app/Http/Controllers/UserPreferencesController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserPreferencesController extends Controller
{
    public function upsert(Request $request)
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'key'   => ['required', 'string', 'max:128', Rule::in([
                'notifications.discord.dm',
                'notifications.discord.channel',
                'notifications.discord.channel-name',
            ])],
            'value' => ['nullable'], // bool|string|null
        ]);

        // Delete override if set back to default (null/empty string)
        if ($data['value'] === null || $data['value'] === '') {
            DB::table('user_preferences')
                ->where('user_id', $userId)
                ->where('key', $data['key'])
                ->delete();

            return response()->json(['ok' => true, 'removed' => true]);
        }

        // Normalize booleans/strings into JSON
        $json = json_encode($data['value']);

        DB::table('user_preferences')->updateOrInsert(
            ['user_id' => $userId, 'key' => $data['key']],
            ['value' => $json, 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json(['ok' => true]);
    }
}
