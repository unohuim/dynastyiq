<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SchedulerController extends Controller
{
    public function index()
    {
        $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
        $payload = collect($events)->map(function ($event) {
            return [
                'command' => $event->command ?? $event->description,
                'expression' => $event->expression,
                'next' => optional($event->nextRunDate())->toDateTimeString(),
                'last' => method_exists($event, 'lastRunDate') ? optional($event->lastRunDate())->toDateTimeString() : null,
            ];
        });

        return view('admin.scheduler', ['events' => $payload]);
    }
}
