<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AnalyticsTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Accepts first-party browser analytics events.
 */
class AnalyticsController extends Controller
{
    /**
     * Store a small batch of browser analytics events.
     */
    public function store(Request $request, AnalyticsTracker $tracker): JsonResponse
    {
        $validated = $request->validate([
            'events' => ['required', 'array', 'max:25'],
            'events.*.event_name' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'events.*.path' => ['nullable', 'string', 'max:2048'],
            'events.*.referrer' => ['nullable', 'string', 'max:2048'],
            'events.*.occurred_at' => ['nullable', 'date'],
            'events.*.properties' => ['nullable', 'array'],
        ]);

        $result = $tracker->track($request, $validated['events']);

        return response()
            ->json(['ok' => true, 'accepted' => $result['accepted']])
            ->cookie(
                AnalyticsTracker::VISITOR_COOKIE,
                $result['visitor_id'],
                $result['cookie_minutes'],
                null,
                null,
                $request->isSecure(),
                true,
                false,
                'Lax',
            )
            ->cookie(
                AnalyticsTracker::SESSION_COOKIE,
                $result['session_id'],
                $result['cookie_minutes'],
                null,
                null,
                $request->isSecure(),
                true,
                false,
                'Lax',
            );
    }
}
