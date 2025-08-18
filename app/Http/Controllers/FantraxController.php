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
}
