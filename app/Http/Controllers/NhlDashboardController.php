<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\NhlDashboardPayload;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Serve the same public hockey dashboard for guests and signed-in users. */
class NhlDashboardController extends Controller
{
    /** Render news and local performance facts without invoking game predictions. */
    public function index(Request $request, NhlDashboardPayload $payload): Response
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return Inertia::render('Dashboard/Index', ['dashboard' => $payload->build(trim((string) ($input['q'] ?? '')))]);
    }
}
