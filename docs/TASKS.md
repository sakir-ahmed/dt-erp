# dt-erp — MVP Task Breakdown (M01 DT-Converse, Phase 1)

Assumption: 2-week sprints, small team (2 backend, 2 frontend, 1 QA/PM). Adjust after team size is confirmed. IDs reference the PRD.

## Sprint 0 — Foundation (Core)
- [ ] Monorepo setup: Laravel + Vue 3 + Vite + Tailwind, linting, CI, env templates
- [ ] Core: auth (Sanctum), users, roles/permissions, settings store, audit log, notifications
- [ ] Redis, Horizon, Reverb, scheduler wired; Docker/dev environment
- [ ] Module skeleton `Converse`, shared UI kit, layout, Bangla font support
- [ ] Brand/feature-toggle service with cache (CF-01, CF-02)

## Sprint 1 — Channels and Inbox (CH, CP basics)
- [ ] Channel abstraction and driver contract
- [ ] Webhook gateway with signature check, raw event store, idempotency (NF-04)
- [ ] Facebook Messenger driver (receive/send) (CH-01)
- [ ] WhatsApp driver incl. templates and 24h window rule (CH-02, CH-10)
- [ ] Contact auto-create, identities (CP-01, CP-02)
- [ ] Inbox UI: list, filters, thread view, human send, realtime (CH-08)
- [ ] Conversation modes, takeover/release, status, tags (HH-03, HH-09)

### Implemented Sprint 1 milestone

- [x] Receive signed Messenger and WhatsApp text events with encrypted raw payload storage, queued processing, and duplicate handling.
- [x] Register and enable or disable channel accounts, with permission checks and audit events.
- [x] Create a contact and channel identity on incoming text and display thread history with search, channel and status filters, and scoped access.
- [x] Save conversation status and handling mode with an audit trail.
- [x] Send human text with mocked provider verification, server-side 24-hour windows, opt-out and mode checks, encrypted channel credentials, and duplicate-operation protection.
- [x] Refresh inbox and thread data via foreground polling.
- [ ] Verify live subscriptions and real development actors. Add approved templates, delivery receipts, and WebSocket presence. AI takeover enforcement depends on the reply engine.
- [x] Exercise OpenAI and Ollama selection, fallback and local-only mode with synthetic playground prompts.
- [x] Maintain audited knowledge collections, product/service records, structured price plans and offers, with scoped publication and validity checks. File indexing and agent-scoped grounding are pending.

## Sprint 2 — AI Brains and Basic Engine (AB, LG, CT)
- [ ] Brain profile CRUD, encrypted keys, test call (AB-01, AB-02)
- [ ] OpenAI and Ollama providers behind one interface
- [ ] Routing rule engine, fallback, local-only, simulate endpoint (AB-03..AB-06)
- [ ] Health checks and usage/cost logging (AB-07..AB-09)
- [ ] Language detection incl. Banglish lexicon (LG-01..LG-04)
- [ ] Understanding step: intent, sentiment, slots (CT-06, CT-07)
- [ ] Context store, rolling summary, topic tracking (CT-02..CT-05)
- [ ] Queue setup: inbound debounce, per-conversation lock, outbound rate limit (AB-10)

## Sprint 3 — Knowledge Base (KB, KF, AC)
- [ ] Collections, products, price plans, offers, company entries, FAQ CRUD UI
- [ ] File upload pipeline: store, parse (md/txt/pdf/docx/csv/xlsx/json), chunk, embed, index (KF-01, KF-04)
- [ ] Vector search plus keyword hybrid, enable/disable and validity filtering (KF-07, KF-09)
- [ ] Knowledge test-search screen, file status UI (realtime)
- [ ] Structured price/offer resolver (AC-02)
- [ ] Markdown editor/preview (KF-03)
- [ ] Source tracing in AI trace (KF-11)

## Sprint 4 — Agents and Guardrails (AG, AC, SD basics)
- [ ] Agent CRUD, persona, tone, guardrails, channel and collection assignment (AG-01..AG-05, AG-09)
- [ ] Prompt template manager with versions (AB-12)
- [ ] Full reply pipeline with output contract, price/grounding checks, confidence threshold (AC-01, AC-04, AC-05)
- [ ] Playground with trace view (AG-11)
- [ ] Sales logic: next-best-action, qualification slots, offer mention rule (SD-02, SD-03, SD-05)
- [ ] Lead stages, auto stage update, contact capture validation (SD-01, SD-10)
- [ ] Human handoff triggers, summary, notifications, agent console details (HH-01, HH-02, HH-05, HH-10)
- [ ] Kill switch and working hours (AD-06, AG-08)

## Sprint 5 — Proactive, SMS, Analytics, Hardening (PM, AN, NF)
- [ ] SMS gateway abstraction, first gateway driver, Unicode/segment calc, logs (PM-04)
- [ ] Consent, opt-out detection (3 languages), exclusions (PM-08, PM-16)
- [ ] Inactivity trigger, quiet hours, frequency caps, approval mode (PM-01, PM-09, PM-11)
- [ ] AI-personalized follow-up generation, reply continuity (PM-03, PM-13)
- [ ] Templates CRUD with language variants (PM-07)
- [ ] Dashboard: overview KPIs, filters (AN-01, AN-10), knowledge gaps list (KF-14)
- [ ] Audit log UI, RBAC polish, system health page (AD-01, AD-03, AD-10)
- [ ] Load test (500 concurrent chats), security review, prompt-injection tests, backup/restore drill
- [ ] Golden Q&A test set run (150+ questions) and tuning
- [ ] UAT with Datatech sales team, go-live checklist

## Definition of Done (every task)
- Code reviewed, tests written (feature tests for API/jobs, unit tests for routing and guardrails, component tests for key UI)
- Permissions and audit logging applied
- Feature toggle where relevant
- Docs updated (`docs/`)
- Works with Bangla, English and Banglish test data

## Phase 2 Backlog (preview)
Router agent and agent-to-agent handoff · lead scoring and recommendations · campaigns and sequences · Instagram and web widget · advanced analytics and cost budgets · AI-suggested replies · identity merge · SLA timers

## Phase 3 Backlog (preview)
A/B testing · voice note transcription · comment-to-inbox · calendar integration · CRM/Billing integration · multi-brand · config export/import · Bangla admin UI

## Key Risks to Track in Sprints
1. Meta app review and WhatsApp approval lead time, so start in Sprint 0.
2. BTRC sender ID and SMS gateway approval lead time.
3. Bangla quality of the local Ollama model, so benchmark in Sprint 2.
4. Knowledge content readiness from business team before Sprint 3.