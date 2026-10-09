<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScheduledProcess;
use App\Services\ScheduledProcessManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Admin-only settings for the first process migrated into the central manager. */
class ScheduledProcessController extends Controller
{
    /** Read the persisted discovery settings without initiating any work. */
    public function show(): JsonResponse
    {
        return response()->json(ScheduledProcess::query()->where('key', ScheduledProcessManager::NHL_DISCOVERY)->firstOrFail());
    }

    /** Persist a toggle or validated timing settings; never dispatch from HTTP. */
    public function update(Request $request, ScheduledProcessManager $manager): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'required', 'boolean'],
            'start_time' => ['sometimes', 'required', 'date_format:H:i'],
            'frequency_hours' => ['sometimes', 'required', 'integer', 'between:1,168'],
            'days_back' => ['sometimes', 'required', 'integer', 'between:1,31'],
        ]);
        $process = DB::transaction(function () use ($data, $manager): ScheduledProcess {
            $process = ScheduledProcess::query()->where('key', ScheduledProcessManager::NHL_DISCOVERY)->lockForUpdate()->firstOrFail();
            $attributes = array_diff_key($data, ['days_back' => true]);
            if (isset($data['days_back'])) {
                $attributes['settings'] = ['days_back' => (int) $data['days_back']];
            }
            $process->fill($attributes);
            if ($process->isDirty(['start_time', 'frequency_hours'])) {
                $process->next_due_at = $process->last_dispatched_at
                    ? $manager->nextDue($process, $process->last_dispatched_at) : null;
            }
            $process->save();

            return $process->fresh();
        });

        return response()->json($process);
    }
}
