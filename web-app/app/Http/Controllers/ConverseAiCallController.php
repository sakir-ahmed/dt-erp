<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ConverseAiCallController extends Controller
{
    private const COLUMNS = [
        'id', 'conversation_id', 'provider', 'model', 'operation', 'origin', 'billing', 'status',
        'agent', 'workflow', 'http_status', 'duration_ms', 'input_tokens', 'output_tokens',
        'total_tokens', 'reported_cost_usd', 'called_at',
    ];

    private const FILTERS = [
        'provider' => ['ollama', 'openrouter', 'openai', 'deepseek', 'anthropic'],
        'billing' => ['local', 'free', 'paid'],
        'status' => ['accepted', 'rejected', 'unavailable'],
    ];

    public function index(Request $request): Response
    {
        abort_if($request->user()->is_demo, 403);

        $query = DB::table('cv_ai_calls');
        $filters = [];
        foreach (self::FILTERS as $column => $values) {
            $value = $request->query($column);
            $filters[$column] = in_array($value, $values, true) ? $value : '';
            if ($filters[$column] !== '') {
                $query->where($column, $value);
            }
        }

        $totals = (clone $query)->selectRaw("
            COUNT(*) AS calls,
            COUNT(CASE WHEN billing IN ('local', 'free') THEN 1 END) AS free_calls,
            COUNT(CASE WHEN billing = 'paid' THEN 1 END) AS paid_calls,
            SUM(total_tokens) AS tokens,
            COUNT(total_tokens) AS calls_with_tokens,
            SUM(reported_cost_usd) AS reported_cost_usd
        ")->first();

        return Inertia::render('Converse/AiCalls', [
            'calls' => $query->orderByDesc('called_at')->orderByDesc('id')
                ->paginate(30, self::COLUMNS)->appends(array_filter($filters))->through($this->metadata(...)),
            'totals' => $totals,
            'filters' => $filters,
            'filterOptions' => self::FILTERS,
        ]);
    }

    public function show(Request $request, int $call): JsonResponse
    {
        abort_if($request->user()->is_demo, 403);

        $row = DB::table('cv_ai_calls')->where('id', $call)->first([...self::COLUMNS, 'detail']);
        abort_unless($row, 404);

        $details = json_decode(Crypt::decryptString($row->detail), true, 512, JSON_THROW_ON_ERROR);
        unset($row->detail);

        return response()->json([...$this->metadata($row), 'detail' => $details], 200, ['Cache-Control' => 'private, no-store']);
    }

    private function metadata(object $row): array
    {
        return [...(array) $row, 'called_at' => Carbon::parse($row->called_at)->toIso8601String()];
    }
}
