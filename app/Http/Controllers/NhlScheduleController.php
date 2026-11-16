<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\NhlScheduleSnapshots;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Read published schedule snapshots without preparing statistics in the HTTP request. */
class NhlScheduleController extends Controller
{
    /** Validate the selected Eastern week and read its prepared display. */
    public function index(Request $request, NhlScheduleSnapshots $snapshots): Response
    {
        $input = $request->validate(['week' => ['nullable', 'date_format:Y-m-d']]);
        $date = isset($input['week']) ? CarbonImmutable::parse($input['week'], 'America/Toronto') : CarbonImmutable::now('America/Toronto');
        $start = $date->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        return Inertia::render('Tools/Schedule', ['schedule' => fn (): array => $snapshots->read($start)]);
    }
}
