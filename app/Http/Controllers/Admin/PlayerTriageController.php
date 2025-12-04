<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FantraxPlayer;
use App\Models\Player;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;

class PlayerTriageController extends Controller
{
    public function index()
    {
        $records = FantraxPlayer::query()
            ->whereNull('player_id')
            ->limit(50)
            ->get()
            ->map(function (FantraxPlayer $player) {
                $candidate = Player::query()
                    ->where('name', $player->name)
                    ->orWhere('full_name', $player->name)
                    ->first();

                return [
                    'id' => $player->id,
                    'platform' => 'fantrax',
                    'name' => $player->name,
                    'suggested' => $candidate,
                ];
            });

        return view('admin.player-triage', ['records' => $records]);
    }

    public function link(Request $request, string $platform, int $id): RedirectResponse
    {
        $request->validate(['player_id' => 'required|integer|exists:players,id']);
        $platformPlayer = FantraxPlayer::findOrFail($id);
        $platformPlayer->update(['player_id' => $request->integer('player_id')]);

        return Redirect::to(URL::route('admin.player-triage'))->with('status', 'Linked');
    }

    public function addVariant(Request $request, string $platform, int $id): RedirectResponse
    {
        $data = $request->validate(['variant' => 'required|string|min:2']);
        $configPath = config_path('first_name_variants.php');
        $variants = config('first_name_variants.variants', []);
        $variants[] = $data['variant'];

        $content = '<?php return ' . var_export(['variants' => array_values(array_unique($variants))], true) . ';';
        file_put_contents($configPath, $content);

        Artisan::call('config:clear');
        Log::info('first_name_variants updated', [
            'user' => $request->user()?->id,
            'variant' => $data['variant'],
        ]);

        return Redirect::to(URL::route('admin.player-triage'))->with('status', 'Variant added');
    }

    public function defer(Request $request, string $platform, int $id): RedirectResponse
    {
        // No-op placeholder for defer; simply acknowledges the record
        return Redirect::to(URL::route('admin.player-triage'))->with('status', 'Deferred');
    }
}
