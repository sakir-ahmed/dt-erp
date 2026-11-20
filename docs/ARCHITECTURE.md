# dt-erp — Architecture (M01 DT-Converse)

## 1. Principles

1. **Modular monolith.** One Laravel app, modules under `app/Modules/*`, each with its own routes, models, services, jobs, migrations. Modules talk through contracts (interfaces) and events, never by touching each other's tables directly.
2. **Core vs module.** Users, roles, settings, audit, notifications and contacts-lite live in `app/Core` so future modules reuse them.
3. **Webhook-first reliability.** Store inbound events immediately, process asynchronously, be idempotent.
4. **Provider abstraction.** Channels, AI brains and SMS gateways sit behind interfaces so new ones are plug-ins.
5. **Everything configurable in DB**, cached, with feature toggles at global/channel/agent level.

## 2. Repository Structure

```
dt-erp/
├── app/
│   ├── Core/
│   │   ├── Auth/ Users/ Roles/ Settings/ Audit/ Notifications/ Support/
│   └── Modules/
│       └── Converse/
│           ├── Channels/            # Drivers: Messenger, WhatsApp, Instagram, WebChat, Sms
│           │   ├── Contracts/ChannelDriver.php
│           │   └── Drivers/
│           ├── Conversations/       # Conversation, Message, Context, Handoff
│           ├── Agents/              # Agent, AgentVersion, Router
│           ├── Brains/              # BrainProfile, RoutingRule, Providers/{OpenAi,Ollama}
│           ├── Knowledge/           # Products, Prices, Offers, Files, Chunks, Retrieval
│           ├── Sales/               # Leads, Stages, Scoring, Playbooks
│           ├── Proactive/           # Triggers, Sequences, Campaigns, Templates, Consent
│           ├── Analytics/
│           ├── Http/ (Controllers, Requests, Resources)
│           ├── Jobs/ Events/ Listeners/ Policies/
│           ├── Database/ (migrations, seeders, factories)
│           └── routes/ (api.php, webhooks.php)
├── resources/js/
│   ├── app.js  router/  stores/  api/  composables/  layouts/
│   ├── modules/converse/
│   │   ├── inbox/  agents/  brains/  knowledge/  leads/
│   │   ├── campaigns/  analytics/  settings/  playground/
│   └── shared/ (components, ui, i18n)
├── routes/ config/ tests/ docs/
```

## 3. Component Diagram

```
 Facebook / WhatsApp / Instagram / Web widget / SMS gateway
                │  (webhooks)                ▲ (send API)
                ▼                            │
        ┌─────────────────┐          ┌───────────────┐
        │ Webhook Gateway │          │ Outbound Sender│◄── rate limits, policy checks,
        │ verify+store raw│          │ (per channel)  │    consent, quiet hours
        └───────┬─────────┘          └───────▲───────┘
                ▼ queue: inbound                │ queue: outbound
        ┌─────────────────┐                     │
        │ Message Ingestor│  normalize, dedupe, contact resolve
        └───────┬─────────┘                     │
                ▼                               │
        ┌─────────────────────────────────────────────┐
        │              Conversation Engine             │
        │ lock → context → language → intent → agent   │
        │ → retrieval → brain route → generate →       │
        │ guardrails → persist → send                  │
        └───────┬─────────────────────┬───────────────┘
                │                     │
        ┌───────▼──────┐      ┌───────▼────────┐
        │ Knowledge    │      │ Brain Gateway  │──► OpenAI API
        │ (pgvector)   │      │ route+fallback │──► Ollama (local)
        └──────────────┘      └────────────────┘

 Scheduler ─► Proactive Engine (triggers, sequences) ─► outbound queue
 Reverb ─► Vue inbox (live updates)
```

## 4. Queues

| Queue | Purpose | Notes |
|-------|---------|-------|
| `webhooks` | Raw event persistence and normalization | Highest priority, tiny jobs |
| `inbound` | Run conversation engine per message | Per-conversation lock/debounce (2–4 s burst window) |
| `outbound` | Send messages via channel/SMS | Rate-limited per channel account |
| `ai-local` | Ollama jobs | Concurrency capped to hardware |
| `ai-cloud` | OpenAI jobs | Concurrency and budget capped |
| `knowledge` | Parse, chunk, embed files | Retry with backoff |
| `proactive` | Trigger evaluation, campaign fan-out | Scheduled batches |
| `analytics` | Aggregates | Low priority |

## 5. Key Runtime Rules

- **Idempotency:** unique `(channel_account_id, external_message_id)`.
- **One writer per conversation:** Redis lock; bursts merged into one AI turn.
- **Human present = AI silent:** engine checks `conversations.mode` (`ai`, `human`, `paused`) before every generation and again before sending.
- **Kill switch:** cached flags `ai.global_enabled`, `proactive.global_enabled`, per channel/agent; checked at ingest, generation and send.
- **Local-only:** flag on agent, collection or contact; the Brain Gateway refuses cloud providers for such requests.
- **Tracing:** each AI message stores brain, model, prompt version, chunk IDs, token usage, latency, route decision.

## 6. Frontend Architecture

- Vue 3 SPA, Pinia stores per domain, API layer with typed wrappers, Echo for realtime.
- Main screens: Inbox, Contact profile, Agents, Brain profiles and routing rules, Knowledge (products, prices, offers, files, gaps), Leads board (kanban), Campaigns and templates, Analytics, Settings (channels, SMS, toggles, roles), Playground.
- Permission-driven menus (backend policies are the source of truth).
- Bangla-safe fonts (e.g., Noto Sans Bengali), Unicode everywhere.

## 7. Security

- Sanctum cookie auth for SPA, 2FA optional, policy-based authorization.
- Secrets encrypted (Laravel `encrypted` cast), never returned by API after save.
- Webhook signature verification (Meta `X-Hub-Signature-256`, gateway tokens).
- Prompt-injection defenses: user text always in user role, knowledge delimited and treated as data, output filter for internal-only content and key patterns.
- Audit log via model observers for config entities.

## 8. Deployment (baseline)

- Nginx + PHP-FPM, Supervisor or Horizon for workers, Redis, PostgreSQL, Reverb process, scheduler cron.
- Ollama on the local GPU/CPU host, reachable over private network.
- Environments: local, staging, production; sandbox mode in staging (no real sends).
- Daily DB and storage backups; log shipping and error tracking.