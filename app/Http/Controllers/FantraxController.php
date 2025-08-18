<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class FantraxController extends Controller
{
    public function index()
    {
        $leagues = auth()->user()?->fantraxLeagues()->get() ?? collect();

        
        return view('fantrax.leagues', [
            'leagues' => $leagues,
        ]);
    }



    /**
     * Show the form for creating a new Fantrax league.
     */
    public function create()
    {

        return true;
        // return view('fantrax.leagues.create', [
        //     'league' => new FantraxLeague(), // handy for form model binding
        // ]);
    }


    /**
     * Display the specified Fantrax league.
     */
    public function show(FantraxLeague $league)
    {
        return "show";
        // return view('fantrax.leagues.show', [
        //     'league' => $league,
        // ]);
    }



    // ...

    /**
     * Update the specified Fantrax league.
     */
    public function update(Request $request, FantraxLeague $league)
    {
        // $validated = $request->validate([
        //     'league_name' => ['required', 'string', 'max:255'],
        //     // add more fields as needed
        // ]);

        // $league->update($validated);

        return "update";
        // return redirect()
        //     ->route('fantrax.leagues.show', $league)
        //     ->with('status', 'League updated successfully!');
    }



    /**
     * Show the form for editing the specified Fantrax league.
     */
    public function edit(FantraxLeague $league)
    {
        return "edit";
        // return view('fantrax.leagues.edit', [
        //     'league' => $league,
        // ]);
    }


    /**
     * Remove the specified Fantrax league.
     */
    public function destroy(FantraxLeague $league)
    {
        $league->delete();

        return redirect()
            ->route('fantrax.leagues.index')
            ->with('status', 'League deleted successfully!');
    }


    /**
     * Sync the specified Fantrax league with the external Fantrax API.
     */
    public function sync(FantraxLeague $league, FantraxApiService $fantrax)
    {
        try {
            $fantrax->syncLeague($league);

            return redirect()
                ->route('fantrax.leagues.show', $league)
                ->with('status', 'League synced successfully!');
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('fantrax.leagues.show', $league)
                ->with('error', 'Failed to sync league. Please try again later.');
        }
    }
}
