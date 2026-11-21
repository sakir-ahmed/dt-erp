<?php

namespace App\Http\Controllers;

use App\Actions\AiProviderCredentials;
use App\Actions\ComposeConverseReply;
use App\Actions\ConverseAiSettings;
use App\Actions\RouteConverseDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ConversePlaygroundController extends Controller
{
    private const array SAMPLES = [
        'bn' => 'হ্যালো, আমি কি একজন প্রতিনিধির সঙ্গে কথা বলতে পারি?',
        'en' => 'Hello, could I speak to a team member?',
        'banglish' => 'Hello, ami ki ekjon agent er shathe kotha bolte pari?',
    ];

    private const array KNOWLEDGE_SAMPLES = [
        'en' => 'How does the sample project board work?',
        'bn' => 'নমুনা প্রজেক্ট বোর্ড কীভাবে কাজ করে?',
        'banglish' => 'Sample project board kivabe kaj kore?',
    ];

    public function development(): Response
    {
        $markdown = file_get_contents(resource_path('docs/converse-development.md'));

        return Inertia::render('Converse/Development', [
            'runbook' => Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
        ]);
    }

    public function index(Request $request, ConverseAiSettings $configuration): Response
    {
        $demo = (bool) $request->user()->is_demo;
        $settings = $demo ? null : $configuration->public();

        return Inertia::render('Converse/Playground', [
            'ollamaAvailable' => $settings['ollama_enabled'] ?? true,
            'channels' => $demo ? [] : DB::table('cv_channel_accounts')->where('is_demo', false)->where('enabled', true)->orderBy('name')
                ->get(['id', 'name', 'type'])->map(fn (object $channel): array => [
                    ...((array) $channel), 'collections' => $settings['channels'][$channel->id]['collections'] ?? [],
                ]),
            'collections' => $demo ? [] : DB::table('cv_knowledge_collections')->where('is_demo', false)
                ->where('enabled', true)->orderBy('name')->get(['id', 'name']),
            'samples' => self::SAMPLES,
            'knowledgeSamples' => self::KNOWLEDGE_SAMPLES,
            'sandboxAvailable' => $this->sandboxCollection() !== null,
            'cloudAvailable' => ! $demo && $settings['allow_cloud'] === true && $settings['cloud_providers'] !== [],
            'cloudProviders' => $demo ? [] : array_intersect_key(AiProviderCredentials::PROVIDERS, array_flip($settings['cloud_providers'])),
        ]);
    }

    public function run(Request $request, RouteConverseDraft $draft, ComposeConverseReply $compose, ConverseAiSettings $configuration): JsonResponse
    {
        $demo = (bool) $request->user()->is_demo;
        abort_if($demo && $request->input('mode') === 'knowledge', 403);
        $started = hrtime(true);
        $data = $request->validate([
            'language' => ['required', Rule::in(array_keys(self::SAMPLES))],
            'local_only' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', Rule::in(['routing', 'knowledge'])],
            'provider_mode' => ['sometimes', Rule::in(['local', 'configured', 'cloud'])],
            'cloud_provider' => ['exclude_unless:provider_mode,cloud', 'nullable', Rule::in(array_keys(AiProviderCredentials::PROVIDERS))],
            'sandbox' => ['sometimes', 'boolean'],
            'complex' => ['sometimes', 'boolean'],
            'message' => ['exclude_unless:mode,knowledge', 'required', 'string', 'min:2', 'max:2000'],
            'collection' => ['exclude_unless:mode,knowledge', 'nullable', 'integer', Rule::exists('cv_knowledge_collections', 'id')
                ->where('is_demo', false)->where('enabled', true)],
            'channel' => ['exclude_unless:mode,knowledge', 'nullable', 'integer', Rule::exists('cv_channel_accounts', 'id')
                ->where('is_demo', false)->where('enabled', true)],
        ]);
        if (($data['mode'] ?? 'routing') === 'knowledge') {
            $channel = isset($data['channel']) ? DB::table('cv_channel_accounts')->where('is_demo', false)
                ->where('enabled', true)->find($data['channel'], ['id', 'type']) : null;
            if (isset($data['channel']) && $channel === null) {
                throw ValidationException::withMessages(['channel' => __('workspace.playground_collection_unavailable')]);
            }
            $settings = $channel === null ? [
                'collections' => DB::table('cv_knowledge_collections')->where('is_demo', false)->where('enabled', true)->pluck('id')->all(),
            ] : [...$configuration->channel((int) $channel->id), 'channel' => $channel->type];
            $allowed = $settings['collections'] ?? [];
            $scope = isset($data['collection']) ? array_values(array_intersect($allowed, [$data['collection']])) : $allowed;
            if ($scope === []) {
                throw ValidationException::withMessages(['collection' => __('workspace.playground_collection_unavailable')]);
            }

            $turn = $compose(collect([(object) ['direction' => 'in', 'body' => $data['message']]]), [
                ...$settings, 'local_only' => true,
                'knowledge_scope_mode' => isset($data['collection']) ? 'manual' : 'automatic',
                'knowledge_collection_ids' => isset($data['collection']) ? $scope : [],
            ]);

            return $this->knowledgeResult($turn, $started);
        }
        if ($data['sandbox'] ?? false) {
            $collection = $this->sandboxCollection();
            abort_if($collection === null, 404);
            $sample = self::KNOWLEDGE_SAMPLES[$data['language']];
            $turn = $compose(collect([(object) ['direction' => 'in', 'body' => $sample]]), [
                'collections' => [$collection], 'sandbox' => true,
            ]);

            return $this->knowledgeResult($turn, $started);
        }

        $providerMode = $data['provider_mode'] ?? (($data['local_only'] ?? true) ? 'local' : 'configured');
        $localOnly = $demo || $providerMode === 'local';
        $language = match ($data['language']) {
            'bn' => 'Bangla in Bengali script',
            'banglish' => 'Banglish (Bangla written in Latin script)',
            default => 'English',
        };
        $result = $draft(
            "This is an isolated routing test. Write a single sample acknowledgement in {$language}. Do not promise any action, name a product, or claim a handoff actually happened.",
            self::SAMPLES[$data['language']],
            $localOnly,
            complex: $data['complex'] ?? false,
            cloudOnly: ! $demo && $providerMode === 'cloud',
            cloudProvider: $demo ? null : ($data['cloud_provider'] ?? null),
        );

        return response()->json(['draft' => $result, 'duration_ms' => $this->duration($started)]);
    }

    private function knowledgeResult(array $turn, int $started): JsonResponse
    {
        return response()->json([
            'draft' => [
                'text' => $turn['body'], 'messages' => $turn['messages'] ?? [$turn['body']],
                'decision' => $turn['decision'], 'intent' => $turn['intent'], 'scope' => $turn['scope'],
                'provider' => $turn['provider'] ?? null, 'model' => $turn['model'] ?? null,
                'fallback' => $turn['fallback'] ?? false, 'source_ids' => $turn['source_ids'],
                'sources' => DB::table('cv_knowledge_entries')->whereIn('id', $turn['source_ids'])->get(['id', 'title']),
                'attachment' => $turn['asset_title'] ?? null,
            ],
            'duration_ms' => $this->duration($started),
        ]);
    }

    private function duration(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }

    private function sandboxCollection(): ?int
    {
        if (! app()->environment('local')) {
            return null;
        }

        $id = DB::table('cv_knowledge_collections')->where([
            'name' => 'Sandbox examples · fictional data', 'enabled' => true, 'is_demo' => true,
        ])->value('id');

        return $id === null ? null : (int) $id;
    }
}
