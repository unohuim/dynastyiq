<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImportCapWagesJob;
use App\Jobs\ImportFantraxPlayersJob;
use App\Jobs\ImportNHLPlayerJob;
use App\Services\PlatformState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;

class InitializationController extends Controller
{
    public function __construct(private PlatformState $platformState)
    {
    }

    public function index(Request $request)
    {
        $batch = null;
        if ($request->filled('batch_id')) {
            $batch = Bus::findBatch($request->input('batch_id'));
        }

        return view('admin.initialize', [
            'initialized' => $this->platformState->initialized(),
            'batch' => $batch,
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        abort_if($this->platformState->initialized(), 403);

        $batch = Bus::batch([
            new ImportNHLPlayerJob(),
            new ImportFantraxPlayersJob(),
            new ImportCapWagesJob(),
            function () {
                // simple referential validation: ensure required records exist
                if (! $this->platformState->initialized()) {
                    throw new \RuntimeException('Initialization validation failed.');
                }
            },
        ])->name('platform-initialization')
            ->allowFailures()
            ->onQueue('default')
            ->dispatch();

        return Redirect::to(URL::route('admin.initialize.index', ['batch_id' => $batch->id]));
    }
}
