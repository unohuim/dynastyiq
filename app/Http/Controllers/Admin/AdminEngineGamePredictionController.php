<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminEngineGamePrediction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Persist bounded, private browser diagnostics under existing SAT admin authorization. */
class AdminEngineGamePredictionController extends Controller
{
    /** List only the current user's saves without loading their result payloads. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['saves' => AdminEngineGamePrediction::query()
            ->where('user_id', $request->user()->id)->orderByDesc('updated_at')->orderByDesc('id')
            ->get(['id', 'name', 'autosave_slot', 'updated_at'])]);
    }

    /** Restore a stored snapshot without prediction assembly or provider calls. */
    public function show(Request $request, int $save): JsonResponse
    {
        return response()->json($this->owned($request, $save));
    }

    /** Create a named snapshot or rotate/update a private autosave, serialized per user. */
    public function store(Request $request): JsonResponse
    {
        $rules = [
            'name' => ['required_without:session_token', 'nullable', 'string', 'max:120', 'not_regex:/^autosave-/i'],
            'session_token' => ['nullable', 'uuid'],
            'autosave_id' => ['nullable', 'integer', 'min:1', 'required_with:session_token'],
            'snapshot' => ['required', 'array:version,stack,member,date,rows,sort'],
            'snapshot.version' => ['required', 'integer', 'in:1,2'],
            'snapshot.stack' => ['required', 'array:id,name,production_model_run_id'],
            'snapshot.stack.id' => ['required', 'integer', 'min:1'],
            'snapshot.stack.name' => ['required', 'string', 'max:160'],
            'snapshot.stack.production_model_run_id' => ['nullable', 'integer'],
            'snapshot.member' => ['required', 'array:id,engine'],
            'snapshot.member.id' => ['required', 'integer', 'min:1'],
            'snapshot.member.engine' => ['required', 'array:id,name,settings,model_run_id,test_model_run_id'],
            'snapshot.member.engine.id' => ['required', 'integer', 'min:1'],
            'snapshot.member.engine.name' => ['required', 'string', 'max:255'],
            'snapshot.member.engine.settings' => ['required', 'array'],
            'snapshot.member.engine.model_run_id' => ['nullable', 'integer'],
            'snapshot.member.engine.test_model_run_id' => ['nullable', 'integer'],
            'snapshot.date' => ['required', 'date_format:Y-m-d'],
            'snapshot.rows' => ['present', 'array', 'max:128'],
            'snapshot.rows.*' => ['required', 'array:id,key,source,model,modelName,game,status,score,spread,skater,goalie,internal,presentation,qualified,error'],
            'snapshot.rows.*.id' => ['required', 'integer'],
            'snapshot.rows.*.key' => ['required', 'string', 'max:80', 'distinct'],
            'snapshot.rows.*.source' => ['required', 'in:production,test'],
            'snapshot.rows.*.model' => ['required', 'string', 'max:40'],
            'snapshot.rows.*.modelName' => ['nullable', 'string', 'max:255'],
            'snapshot.rows.*.game' => ['required', 'string', 'max:80'],
            'snapshot.rows.*.status' => ['required', 'in:Waiting,Predicting,Calculated,Unavailable,Failed,Stopped'],
            'snapshot.rows.*.score' => ['nullable', 'string', 'max:80'],
            'snapshot.rows.*.spread' => ['nullable', 'numeric'],
            'snapshot.rows.*.skater' => ['nullable', 'numeric', 'between:0,100'],
            'snapshot.rows.*.goalie' => ['nullable', 'numeric', 'between:0,100'],
            'snapshot.rows.*.internal' => ['nullable', 'numeric', 'between:0,100'],
            'snapshot.rows.*.presentation' => ['nullable', 'numeric', 'between:0,100'],
            'snapshot.rows.*.qualified' => ['nullable', 'boolean'],
            'snapshot.rows.*.error' => ['nullable', 'string', 'max:4000'],
            'snapshot.sort' => ['required', 'array:key,direction'],
            'snapshot.sort.key' => ['required', 'in:game,model,score,spread,skater,goalie,internal,presentation,qualified'],
            'snapshot.sort.direction' => ['required', 'integer', 'in:-1,1'],
        ];
        if ((int) $request->input('snapshot.version') === 2) {
            $rules['snapshot'] = ['required', 'array:version,stack,sections'];
            $rules['snapshot.sections'] = ['required', 'array', 'min:1', 'max:128'];
            $rules['snapshot.sections.*'] = ['required', 'array:member,date,rows,sort,open,error,status'];
            $rules['snapshot.sections.*.open'] = ['required', 'boolean'];
            $rules['snapshot.sections.*.error'] = ['nullable', 'string', 'max:4000'];
            $rules['snapshot.sections.*.status'] = ['required', 'in:Waiting,Predicting,Calculated,Failed,Stopped'];
            // Reuse the v1 member/row contracts inside each independently sortable accordion.
            foreach (array_keys($rules) as $key) {
                if (preg_match('/^snapshot\.(member|date|rows|sort)(\.|$)/', $key)) {
                    $nestedKey = 'snapshot.sections.*.' . substr($key, strlen('snapshot.'));
                    $rules[$nestedKey] = array_values(array_diff($rules[$key], ['distinct']));
                    unset($rules[$key]);
                }
            }
            $rules['snapshot.sections.*.date'] = ['nullable', 'date_format:Y-m-d'];
            $rules['snapshot.sections.*.member.engine.id'][] = 'distinct';
        }
        $input = $request->validate($rules);
        if (strlen(json_encode($input['snapshot'], JSON_THROW_ON_ERROR)) > 262144) {
            throw ValidationException::withMessages(['snapshot' => 'Save is too large (maximum 256 KiB).']);
        }
        $result = DB::transaction(function () use ($request, $input): AdminEngineGamePrediction {
            User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $query = AdminEngineGamePrediction::query()->where('user_id', $request->user()->id);
            if (! empty($input['session_token'])) {
                $save = (clone $query)->whereKey($input['autosave_id'])->firstOrFail();
                abort_unless($save->autosave_slot !== null && hash_equals((string) $save->session_token, $input['session_token']), 409,
                    'This autosave slot has been replaced. Start a new run or save a named copy.');
                $save->update(['snapshot' => $input['snapshot']]);

                return $save;
            }
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '' || preg_match('/^autosave-/i', $name)) {
                throw ValidationException::withMessages(['name' => 'Choose a nonempty name that does not start with autosave-.']);
            }
            if ((clone $query)->whereNull('autosave_slot')->count() >= 50) {
                throw ValidationException::withMessages(['name' => 'You have 50 named saves. Delete one before saving another.']);
            }
            if ((clone $query)->where('name', $name)->exists()) {
                throw ValidationException::withMessages(['name' => 'That name is already saved. Choose a different name.']);
            }

            return AdminEngineGamePrediction::query()->create([
                'user_id' => $request->user()->id, 'name' => $name, 'snapshot' => $input['snapshot'],
            ]);
        });

        return response()->json($result);
    }

    /** Reserve one of four autosave slots; old tab tokens cannot overwrite a reused slot. */
    public function autosave(Request $request): JsonResponse
    {
        $input = $request->validate(['session_token' => ['required', 'uuid']]);
        $save = DB::transaction(function () use ($request, $input): AdminEngineGamePrediction {
            User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $saves = AdminEngineGamePrediction::query()->where('user_id', $request->user()->id)
                ->whereNotNull('autosave_slot')->orderBy('updated_at')->orderBy('id')->get();
            $existing = $saves->firstWhere('session_token', $input['session_token']);
            if ($existing !== null) {
                return $existing;
            }
            $slot = collect(range(1, 4))->diff($saves->pluck('autosave_slot'))->first();
            if ($slot === null) {
                $oldest = $saves->first();
                $slot = $oldest->autosave_slot;
                $oldest->delete();
            }
            $save = new AdminEngineGamePrediction([
                'user_id' => $request->user()->id, 'name' => 'autosave-' . $slot, 'autosave_slot' => $slot,
            ]);
            $save->fill(['session_token' => $input['session_token'], 'snapshot' => []])->save();

            return $save;
        });

        return response()->json($save);
    }

    /** Delete only a save owned by the authenticated admin. */
    public function destroy(Request $request, int $save): JsonResponse
    {
        DB::transaction(function () use ($request, $save): void {
            User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->owned($request, $save)->delete();
        });

        return response()->json(['deleted' => true]);
    }

    /** Scope every record lookup to its owner, including super admins. */
    private function owned(Request $request, int $save): AdminEngineGamePrediction
    {
        return AdminEngineGamePrediction::query()->where('user_id', $request->user()->id)->findOrFail($save);
    }
}
