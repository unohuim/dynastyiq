<?php

/**
 * Controller for dispatching player import jobs.
 *
 * @package App\Http\Controllers
 */

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ImportFantraxPlayersJob;
use App\Jobs\ImportPlayersJob;
use App\Traits\HasAPITrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Jobs\ImportCapWagesJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Bus\Batch;
use Throwable;




/**
 * Class PlayerImportController
 *
 * Handles dispatching of various player import jobs,
 * including NHL roster imports and Fantrax backfills.
 *
 * @package App\Http\Controllers
 */
class PlayerImportController extends Controller
{
    use HasAPITrait;



    /**
     * Dispatch a daily chain of player import jobs in sequence:
     * 1. NHL player imports (one per team) are dispatched as a batch.
     * 2. After all NHL jobs complete, Fantrax import is dispatched.
     * 3. After Fantrax completes, CapWages import is dispatched.
     *
     * Only accessible to users with the 'super-admin' role.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function importDaily(): JsonResponse
    {
        abort_unless(
            Auth::user()?->hasRole('super-admin'),
            403,
            'Forbidden'
        );

        $standings = $this->getAPIData('nhl', 'standings_now');

        $teams = collect($standings['standings'])
            ->pluck('teamAbbrev.default')
            ->filter()
            ->unique()
            ->values();

        $nhlJobs = $teams->map(
            fn (string $abbrev) => new ImportPlayersJob($abbrev)
        )->all();

        Bus::batch($nhlJobs)
            ->then(function (Batch $batch): void {
                ImportFantraxPlayersJob::withChain([
                    new ImportCapWagesJob(),
                ])->dispatch();
            })
            ->catch(function (Batch $batch, Throwable $e): void {
                logger()->error('NHL import batch failed', [
                    'error' => $e->getMessage(),
                    'batchId' => $batch->id,
                ]);
            })
            ->name('DailyImport:NHL → Fantrax → CapWages')
            ->dispatch();

        return response()->json([
            'message' => 'Daily import started: NHL → Fantrax → CapWages',
            'teams' => $teams,
        ]);
    }





    /**
     * Dispatch NHL player import jobs for all active teams.
     *
     * Each NHL team will be processed using a queued ImportPlayersJob.
     * This does NOT trigger Fantrax or CapWages jobs — use /import/daily for full workflow.
     *
     * Only accessible to users with the 'super-admin' role.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function importNHL(): JsonResponse
    {
        abort_unless(
            Auth::user()?->hasRole('super-admin'),
            403,
            'Forbidden'
        );

        $standings = $this->getAPIData('nhl', 'standings_now');

        $teams = collect($standings['standings'])
            ->pluck('teamAbbrev.default')
            ->filter()
            ->unique()
            ->values();

        foreach ($teams as $abbrev) {
            ImportPlayersJob::dispatch($abbrev);
        }

        return response()->json([
            'message' => 'NHL player imports queued',
            'teams'   => $teams,
        ]);
    }

    

    /**
     * Manually trigger the Fantrax backfill.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function importFantrax(): JsonResponse
    {
        
        abort_unless(
            Auth::user()?->hasRole('super-admin'),
            403,
            'Forbidden'
        );

        ImportFantraxPlayersJob::dispatch();

        return response()->json([
            'message' => 'Fantrax import job dispatched',
        ]);
    }


    /**
     * Dispatch the CapWages import job, which will page through
     * the players endpoint and import contract details for each slug.
     *
     * @return JsonResponse
     */
    public function importCapWages(): JsonResponse
    {
        abort_unless(
            Auth::user()?->hasRole('super-admin'),
            403,
            'Forbidden'
        );

        // You may pass a custom per-page limit, e.g. ImportCapWagesJob::dispatch(50);
        ImportCapWagesJob::dispatch();

        return response()->json([
            'message' => 'CapWages contract import job dispatched',
        ]);
    }

}
