<?php

namespace App\Http\Controllers;

use App\Actions\ComposeConverseReply;
use App\Actions\ConverseAiSettings;
use App\Actions\RecordConverseAudit;
use App\Actions\RouteConverseDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            'providerSettings' => $settings,
            'channels' => $demo ? [] : DB::table('cv_channel_accounts')->where('is_demo', false)->orderBy('name')
                ->select('id', 'name', 'type', 'enabled')->selectRaw('CASE WHEN send_token IS NULL THEN 0 ELSE 1 END as has_send_token')->get(),
            'outboundEnabled' => ! $demo && config('converse.outbound_enabled') === true,
            'collections' => $demo ? [] : DB::table('cv_knowledge_collections')->where('is_demo', false)
                ->where('enabled', true)->orderBy('name')->get(['id', 'name']),
            'samples' => self::SAMPLES,
            'knowledgeSamples' => self::KNOWLEDGE_SAMPLES,
            'sandboxAvailable' => $this->sandboxCollection() !== null,
            'cloudAvailable' => ! $demo && $settings['allow_cloud'] === true,
        ]);
    }

    public function saveProviders(Request $request, RecordConverseAudit $audit): RedirectResponse
    {
        abort_if($request->user()->is_demo, 403);
        $data = $request->validate([
            'primary' => ['required', Rule::in(['ollama', 'openai'])],
            'allow_cloud' => ['required', 'boolean'],
            'ollama_port' => ['required', 'integer', 'between:1,65535'],
            'ollama_model' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9._-]*(?::[a-z0-9._-]+)?$/iD', 'not_regex:/cloud/i'],
            'ollama_fallback_model' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9._-]*(?::[a-z0-9._-]+)?$/iD', 'not_regex:/cloud/i'],
            'embedding_model' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9._-]*(?::[a-z0-9._-]+)?$/iD', 'not_regex:/cloud/i'],
            'openai_model' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_.:-]+$/D'],
            'openai_key' => ['nullable', 'string', 'max:4096', 'prohibited_if:remove_openai_key,true'],
            'remove_openai_key' => ['required', 'boolean'],
        ]);
        $secret = $data['openai_key'] ?? null;
        $remove = $data['remove_openai_key'];
        unset($data['openai_key'], $data['remove_openai_key']);

        DB::transaction(function () use ($request, $audit, $data, $secret, $remove): void {
            $changes = $data;
            if ($remove || $secret !== null) {
                $changes['openai_key'] = $remove ? null : Crypt::encryptString($secret);
                $changes['openai_key_removed'] = (bool) $remove;
            }

            DB::table('cv_ai_settings')->where('id', 1)->update($changes);
            $audit($request->user()->id, 'ai.providers_saved', 'ai_settings', 1, [
                ...$data, 'key_changed' => $remove || $secret !== null, 'key_removed' => (bool) $remove,
            ]);
        });

        return to_route('converse.playground.index');
    }

    public function saveRouting(Request $request, RecordConverseAudit $audit): RedirectResponse
    {
        abort_if($request->user()->is_demo, 403);
        $model = ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9._-]*(?::[a-z0-9._-]+)?$/iD', 'not_regex:/cloud/i'];
        $collection = Rule::exists('cv_knowledge_collections', 'id')->where('is_demo', false);
        $json = $request->validate(['routing' => ['required', 'json', 'max:30000']]);
        $routing = json_decode($json['routing'], true, flags: JSON_THROW_ON_ERROR);
        $data = validator(is_array($routing) ? $routing : [], [
            'agents' => ['required', 'array', 'min:1', 'max:30'],
            'agents.*' => ['required', 'array:name,role,enabled,model,fallback_model,collections,language,tone,instructions'],
            'agents.*.name' => ['sometimes', 'required', 'string', 'max:80'],
            'agents.*.role' => ['sometimes', 'required', 'string', 'max:80'],
            'agents.*.enabled' => ['sometimes', 'boolean'],
            'agents.*.model' => $model,
            'agents.*.fallback_model' => $model,
            'agents.*.collections' => ['sometimes', 'array', 'max:25'],
            'agents.*.collections.*' => ['required', 'integer', $collection],
            'agents.*.language' => ['nullable', Rule::in(['bn', 'en', 'banglish'])],
            'agents.*.tone' => ['nullable', 'string', 'max:80'],
            'agents.*.instructions' => ['nullable', 'string', 'max:1000'],
            'rules' => ['present', 'array', 'list', 'max:50'],
            'rules.*' => ['required', 'array:when,model,fallback_model'],
            'rules.*.when' => ['present', 'array:agent,channel,language,intent'],
            'rules.*.when.agent' => ['sometimes', 'required', 'string', 'max:40'],
            'rules.*.when.channel' => ['sometimes', Rule::in(['messenger', 'whatsapp'])],
            'rules.*.when.language' => ['sometimes', Rule::in(['bn', 'en', 'banglish'])],
            'rules.*.when.intent' => ['sometimes', Rule::in(['greeting', 'human_request', 'price', 'offer', 'support', 'sales_request', 'inquiry'])],
            'rules.*.model' => ['required', ...array_slice($model, 1)],
            'rules.*.fallback_model' => $model,
            'cloud' => ['sometimes', 'required', 'array:live,models'],
            'cloud.live' => ['required_with:cloud', 'boolean'],
            'cloud.models' => ['present_with:cloud', 'array', 'list', 'max:8'],
            'cloud.models.*' => ['required', 'array:provider,model,tier'],
            'cloud.models.*.provider' => ['required', Rule::in(['openrouter', 'deepseek', 'openai', 'anthropic'])],
            'cloud.models.*.model' => ['required', 'string', 'max:150', 'regex:~^[A-Za-z0-9][A-Za-z0-9_.:/-]*$~D'],
            'cloud.models.*.tier' => ['required', Rule::in(['free', 'economy', 'advanced'])],
        ])->validate();

        foreach (array_keys($data['agents']) as $agent) {
            abort_unless(is_string($agent) && preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $agent), 422);
        }
        foreach ($data['rules'] as $rule) {
            abort_if(isset($rule['when']['agent']) && ! array_key_exists($rule['when']['agent'], $data['agents']), 422);
        }
        foreach ($data['cloud']['models'] ?? [] as $candidate) {
            abort_if($candidate['tier'] === 'free' && ($candidate['provider'] !== 'openrouter'
                || ! str_ends_with($candidate['model'], ':free')), 422, 'Free routing requires an OpenRouter :free model.');
        }

        DB::transaction(function () use ($request, $audit, $data): void {
            $table = DB::table('cv_ai_settings')->where('id', 1);
            $saved = $table->lockForUpdate()->firstOrFail();
            $routing = json_decode($saved->routing ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $data += array_intersect_key($routing, ['cloud' => true]);
            $table->update(['routing' => json_encode($data, JSON_THROW_ON_ERROR)]);
            $audit($request->user()->id, 'ai.routing_saved', 'ai_settings', 1, [
                'agent_keys' => array_keys($data['agents']), 'rule_count' => count($data['rules']),
                'cloud' => $data['cloud'] ?? null,
            ]);
        });

        return to_route('converse.playground.index');
    }

    public function saveChannel(Request $request, RecordConverseAudit $audit, ConverseAiSettings $configuration, int $channel): RedirectResponse
    {
        abort_if($request->user()->is_demo, 403);
        abort_unless(DB::table('cv_channel_accounts')->where('id', $channel)->where('is_demo', false)->exists(), 404);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'agent' => ['nullable', Rule::in(array_keys($configuration->get()['agents']))],
            'collections' => ['present', 'array', 'max:25'],
            'collections.*' => ['required', 'distinct', 'integer', Rule::exists('cv_knowledge_collections', 'id')
                ->where('is_demo', false)->where('enabled', true)],
        ]);

        DB::transaction(function () use ($request, $channel, $audit, $data): void {
            $table = DB::table('cv_ai_settings')->where('id', 1);
            $saved = $table->lockForUpdate()->firstOrFail();
            $channels = json_decode($saved->channels ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $channels[$channel] = ['enabled' => (bool) $data['enabled'], 'agent' => $data['agent'] ?? null,
                'collections' => array_map(intval(...), $data['collections'])];
            $table->update(['channels' => json_encode($channels, JSON_THROW_ON_ERROR)]);
            $audit($request->user()->id, 'ai.channel_saved', 'channel', $channel, $channels[$channel]);
        });

        return to_route('converse.playground.index');
    }

    public function run(Request $request, RouteConverseDraft $draft, ComposeConverseReply $compose): JsonResponse
    {
        $data = $request->validate([
            'language' => ['required', Rule::in(array_keys(self::SAMPLES))],
            'local_only' => ['required', 'boolean'],
            'sandbox' => ['sometimes', 'boolean'],
            'complex' => ['sometimes', 'boolean'],
        ]);
        if ($data['sandbox'] ?? false) {
            $collection = $this->sandboxCollection();
            abort_if($collection === null, 404);
            $sample = self::KNOWLEDGE_SAMPLES[$data['language']];
            $turn = $compose(collect([(object) ['direction' => 'in', 'body' => $sample]]), [
                'collections' => [$collection], 'sandbox' => true,
            ]);

            return response()->json(['draft' => [
                'text' => $turn['body'], 'provider' => $turn['provider'] ?? null, 'model' => $turn['model'] ?? null,
                'fallback' => $turn['fallback'] ?? false, 'source_ids' => $turn['source_ids'],
            ]]);
        }

        $localOnly = $request->user()->is_demo || (bool) $data['local_only'];
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
        );

        return response()->json(['draft' => $result]);
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
