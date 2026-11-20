# dt-erp — API Specification (M01 DT-Converse)

Base: `/api/v1/converse` · Auth: Sanctum (SPA cookie) · JSON · Pagination: `?page=&per_page=` · Filters via query params · Errors: `{message, errors{}}` with standard HTTP codes. Every endpoint is permission-guarded.

## 1. Webhooks (public, signature verified)

| Method | Path | Purpose |
|--------|------|---------|
| GET/POST | `/webhooks/meta` | Messenger + Instagram verification and events |
| GET/POST | `/webhooks/whatsapp` | WhatsApp events and statuses |
| POST | `/webhooks/sms/{gateway}` | SMS delivery reports and inbound replies |
| POST | `/webhooks/webchat/{token}` | Website widget messages |

All return 200 quickly after persisting to `cv_webhook_events`.

## 2. Channels

| Method | Path | Purpose |
|--------|------|---------|
| GET/POST | `/channels` | List, connect |
| GET/PUT/DELETE | `/channels/{id}` | View, update, disconnect |
| POST | `/channels/{id}/test` | Connection test |
| POST | `/channels/{id}/toggle` | Enable/disable |

## 3. Inbox and Conversations

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/conversations` | Filters: status, channel, agent, assignee, mode, tag, intent, q |
| GET | `/conversations/{id}` | Detail with context and summary |
| GET | `/conversations/{id}/messages` | Cursor pagination |
| POST | `/conversations/{id}/messages` | Human send (text/media/template) |
| POST | `/conversations/{id}/takeover` | AI paused, human active |
| POST | `/conversations/{id}/release` | Return to AI (optional agent) |
| POST | `/conversations/{id}/assign` | Assign user |
| POST | `/conversations/{id}/status` | open/pending/snoozed/resolved |
| POST | `/conversations/{id}/tags` | Set tags |
| POST | `/conversations/{id}/notes` | Internal note |
| GET | `/conversations/{id}/suggestions` | AI draft replies |
| GET | `/messages/{id}/trace` | Brain, model, sources used |
| GET/POST | `/canned-responses` | Quick replies CRUD |

## 4. Contacts and Leads

| Method | Path | Purpose |
|--------|------|---------|
| GET/POST | `/contacts` | List/search, create |
| GET/PUT/DELETE | `/contacts/{id}` | Profile |
| GET | `/contacts/{id}/timeline` | Messages, notes, events, campaigns |
| POST | `/contacts/merge` | Merge duplicates |
| POST | `/contacts/import` · GET `/contacts/export` | CSV/Excel |
| GET | `/contacts/{id}/data-export` · DELETE `/contacts/{id}/data` | Data subject requests |
| GET/POST/PUT | `/lead-stages` | Funnel configuration |
| POST | `/contacts/{id}/stage` | Move stage |
| GET | `/leads/board` | Kanban data |
| GET/PUT | `/demo-requests`, `/quote-requests` | Sales queue |
| GET/POST/PUT | `/segments` | Saved filters |

## 5. Agents, Brains, Routing

| Method | Path | Purpose |
|--------|------|---------|
| CRUD | `/agents` | Agents |
| POST | `/agents/{id}/clone` · `/toggle` | Clone, enable/disable |
| GET | `/agents/{id}/versions` · POST `/versions/{vid}/restore` | Versioning |
| POST | `/agents/{id}/playground` | Test chat, returns reply plus trace |
| CRUD | `/brains` | Brain profiles |
| POST | `/brains/{id}/test` | Test call |
| GET | `/brains/health` | Status board |
| CRUD | `/routing-rules` · POST `/routing-rules/simulate` | Rules and what-if test |
| CRUD | `/prompt-templates` | Prompts with versions |
| GET | `/usage/ai` | Tokens/cost by brain, agent, day |
| POST | `/system/kill-switch` | `{scope: global|channel|agent|proactive, id?, enabled}` |

## 6. Knowledge

| Method | Path | Purpose |
|--------|------|---------|
| CRUD | `/collections` | Collections |
| CRUD | `/products` · `/products/{id}/faqs` · `/products/{id}/prices` | Catalog and pricing |
| CRUD | `/offers` | Offers |
| CRUD | `/company-entries` · `/faq-library` · `/playbooks` | Other knowledge |
| POST | `/knowledge/files` | Multipart upload (multiple), metadata fields |
| GET | `/knowledge/files` | List with status filters |
| GET/PUT/DELETE | `/knowledge/files/{id}` | Metadata |
| POST | `/knowledge/files/{id}/toggle` · `/reindex` | Enable/disable, reindex |
| GET | `/knowledge/files/{id}/versions` · POST `/restore` | Versions |
| POST | `/knowledge/reindex-all` | Full rebuild |
| POST | `/knowledge/search` | Test retrieval `{query, agent_id}` |
| POST | `/products/import` | CSV/Excel import |
| CRUD | `/attachments` | Sendable files |
| GET | `/knowledge/gaps` · POST `/gaps/{id}/resolve` | Unanswered questions |

## 7. Proactive and SMS

| Method | Path | Purpose |
|--------|------|---------|
| CRUD | `/templates` | Message templates, language variants |
| CRUD | `/triggers` | Follow-up triggers |
| CRUD | `/sequences` | Drip sequences |
| CRUD | `/campaigns` · POST `/campaigns/{id}/start|pause|cancel` | Campaigns |
| GET | `/campaigns/{id}/report` | Sent, delivered, replied, converted |
| GET | `/scheduled-messages` | Queue view; filter status=approval |
| POST | `/scheduled-messages/{id}/approve|reject` | Manual approval |
| GET/POST | `/consents` · `/exclusions` | Consent and exclusion lists |
| CRUD | `/sms-gateways` · POST `/sms-gateways/{id}/test` | Gateways |
| GET | `/sms/balance` · `/sms/logs` | Balance and logs |

## 8. Analytics

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/analytics/overview` | KPIs |
| GET | `/analytics/{channels|agents|products|campaigns|funnel|languages|questions|knowledge|ai-quality}` | Reports |
| GET | `/analytics/export` | CSV/Excel/PDF |
| POST | `/messages/{id}/feedback` | Staff thumbs up/down with reason |

## 9. Admin

| Method | Path | Purpose |
|--------|------|---------|
| CRUD | `/users` · `/roles` | Users and RBAC |
| GET/PUT | `/settings` | Toggles, business hours, holidays, retention |
| GET | `/audit-logs` | Filter by user, entity, date |
| GET | `/system/health` | Queues, webhooks, channels, brains |

## 10. Realtime Events (Reverb / Echo)

Private channels: `converse.inbox`, `converse.conversation.{id}`, `converse.user.{id}`

| Event | Payload highlights |
|-------|--------------------|
| `message.created` | conversation_id, message |
| `message.status` | message_id, delivery_status |
| `conversation.updated` | mode, status, assignee, stage |
| `handoff.requested` | conversation_id, reason, summary |
| `lead.hot` | contact_id, score |
| `knowledge.file.status` | file_id, status |
| `brain.health` | brain_id, status |

## 11. Internal Contracts (for future modules)

- `ContactProvider` (find, create, merge contacts)
- `NotificationPublisher`
- Domain events: `LeadStageChanged`, `DemoRequested`, `QuoteRequested`, `ConversationResolved`, `CustomerOptedOut`