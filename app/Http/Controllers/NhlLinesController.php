<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\NhlTeamLines;
use Inertia\Inertia;
use Inertia\Response;

/** Serve the public most-recent-game lines tool. */
class NhlLinesController extends Controller
{
    /** Read prepared strength summaries without initiating imports or predictions. */
    public function index(NhlTeamLines $lines): Response
    {
        return Inertia::render('Tools/Lines', ['lines' => fn (): array => $lines->read()]);
    }
}
