<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class FantraxController extends Controller
{
    public function index()
    {
        $leagues = auth()->user()?->leagues()->get() ?? collect();

        
        return view('leagues', [
            'leagues' => $leagues,
        ]);
    }


}
