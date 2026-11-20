# dt-erp

Datatech BD Ltd. Enterprise Resource Platform. Single repository: **Laravel** backend + **Vue 3** frontend.

## Modules

| Code | Module | Status |
|------|--------|--------|
| core | Auth, users, roles, settings, audit, notifications | Authentication and user directory available |
| **M01** | **DT-Converse: AI social messaging and sales automation** | **Phase 1 in development** |
| M02+ | CRM, Billing, Projects, Support, HR | Future |

## Documentation Index

| File | Purpose |
|------|---------|
| `docs/PRD.md` | Product requirements (what and why) |
| `docs/ARCHITECTURE.md` | Repo layout, components, runtime flow |
| `docs/DATABASE.md` | Tables and relationships |
| `docs/API.md` | REST endpoints, webhooks, realtime events |
| `docs/AI_ENGINE.md` | Reply pipeline, brain routing, RAG, language rules |
| `docs/TASKS.md` | MVP sprint breakdown |

## Current Phase 1 milestone

The running application is in `web-app/`. DT-Converse has an inbox for signed Messenger and WhatsApp text deliveries, channel account management, guarded human text replies, a sample-only AI playground, and knowledge collections with structured price plans and offers. The standalone Products and Stock Movements screens and routes have been retired. Their historical tables and migration files are retained. The DT-Converse knowledge catalog and pricing are separate from the retired inventory tables.

See [the implemented endpoints](docs/API.md) for local provider setup. The proposed stack below describes the longer-term target.

## Proposed Stack

- Backend: Laravel 11, PHP 8.3, Sanctum (SPA auth), Redis queues + Horizon, Laravel Reverb (websockets), Scheduler
- Frontend: Vue 3, Vite, Pinia, Vue Router, Tailwind CSS, Laravel Echo
- Database: PostgreSQL 16 with pgvector (MySQL + external vector store is the alternative)
- AI: OpenAI API + local Ollama (chat and embeddings)
- Storage: local disk or S3-compatible for knowledge files and media

## Repo Layout (summary)

```
dt-erp/
├── app/Core/                 # shared ERP services
├── app/Modules/Converse/     # M01 backend
├── resources/js/             # Vue SPA
├── database/ routes/ config/ tests/
└── docs/
```

See `docs/ARCHITECTURE.md` for detail.

## Getting Started (target workflow)

```bash
cp .env.example .env
composer install && npm install
php artisan key:generate && php artisan migrate --seed
php artisan horizon &        # queues
php artisan reverb:start &   # realtime
npm run dev
```

Required env groups: `DB_*`, `REDIS_*`, `OPENAI_API_KEY`, `OLLAMA_BASE_URL`, `META_APP_*`, `WHATSAPP_*`, `SMS_GATEWAY_*`.