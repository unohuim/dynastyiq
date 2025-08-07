<?php

namespace App\Http\Controllers;

use App\Services\ImportFantraxLeagues;
use App\Traits\HasAPITrait;
use Illuminate\Http\Request;

class LeagueController extends Controller
{
    use HasAPITrait;

    protected ImportFantraxLeagues $importer;

    public function __construct(ImportFantraxLeagues $importer)
    {
        $this->importer = $importer;
    }

    /**
     * Import Fantrax leagues by fetching with secret_id from request.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function import(Request $request)
    {
        $secretId = "ofkth5mxks3i7ea8"; //$request->input('secret_id');

        if (empty($secretId)) {
            return response()->json(['error' => 'Missing secret_id'], 422);
        }

        // Fetch Fantrax leagues data using HasAPITrait method
        $payload = $this->getAPIData('fantrax', 'user_leagues', ['userSecretId' => $secretId]);

        if (!is_array($payload)) {
            return response()->json(['error' => 'Failed to fetch leagues data'], 500);
        }

        $userId = $request->user()->id; // Authenticated user ID

        $this->importer->import($payload, $userId);

        return response()->json(['message' => 'Leagues imported successfully']);
    }
}
