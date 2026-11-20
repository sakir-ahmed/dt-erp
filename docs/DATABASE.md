# dt-erp — Database Design (M01 DT-Converse)

Conventions: table prefix `cv_` for module tables; `id` bigint PK; `created_at/updated_at`; soft deletes where noted (SD); JSON columns for flexible config; `brand_id` nullable on key tables for future multi-brand.

## 1. Core (shared)

| Table | Key columns |
|-------|-------------|
| `users` | name, email, phone, password, status, two_factor_* |
| `roles`, `permissions`, `role_user`, `permission_role` | RBAC |
| `settings` | scope, key, value(json) — global/channel/agent toggles |
| `audit_logs` | user_id, action, auditable_type/id, old(json), new(json), ip |
| `notifications` | Laravel standard |

## 2. Channels and Contacts

| Table | Key columns |
|-------|-------------|
| `cv_channel_accounts` | type (messenger, whatsapp, instagram, webchat, sms), name, external_id, credentials(encrypted json), status, default_agent_id, settings(json) |
| `cv_webhook_events` | channel_account_id, payload(json), signature_ok, status, processed_at |
| `cv_contacts` (SD) | name, phone, email, company, designation, location, language_pref, source, stage_id, owner_id, lead_score, interests(json), budget_min/max, consent_status, local_only(bool), summary(text), tags |
| `cv_contact_identities` | contact_id, channel_account_id, external_id, profile(json) — unique(channel_account_id, external_id) |
| `cv_contact_notes` | contact_id, user_id, body |
| `cv_segments` | name, filter(json) |

## 3. Conversations

| Table | Key columns |
|-------|-------------|
| `cv_conversations` | contact_id, channel_account_id, agent_id, assigned_user_id, mode (ai/human/paused), status (open/pending/snoozed/resolved), current_topic_id, language, intent, sentiment, last_message_at, first_response_at, tags |
| `cv_messages` | conversation_id, direction (in/out), sender_type (customer/ai/human/system), sender_id, external_id, type (text/image/file/audio/template), body, media(json), language_detected, delivery_status, error, meta(json) — unique(conversation_id, external_id) |
| `cv_ai_traces` | message_id, agent_id, agent_version_id, brain_profile_id, model, prompt_version_id, route_rule_id, route_reason, chunk_ids(json), tokens_in/out, cost, latency_ms, confidence, fallback_used |
| `cv_conversation_context` | conversation_id, topic_product_id, topic_label, slots(json: need, budget, timeline…), rolling_summary, updated_at |
| `cv_handoffs` | conversation_id, from(type/id), to(type/id), reason, summary, created_at |
| `cv_internal_notes` | conversation_id, user_id, body |
| `cv_canned_responses` | title, shortcut, body_bn, body_en, body_banglish |

## 4. Agents and Brains

| Table | Key columns |
|-------|-------------|
| `cv_agents` | name, avatar, role, tone, default_language, greeting, fallback_message, sales_level, local_only, status, working_hours(json), allowed_actions(json), guardrails(json), brain_profile_id, fallback_brain_id, active_version_id |
| `cv_agent_versions` | agent_id, config_snapshot(json), prompt_template_id, created_by, note |
| `cv_agent_channel` | agent_id, channel_account_id |
| `cv_agent_collection` | agent_id, collection_id |
| `cv_brain_profiles` | name, provider (openai/ollama), model, endpoint, credentials(encrypted), temperature, max_tokens, timeout, cost_in, cost_out, supports(json: bangla, json_mode, tools), status |
| `cv_routing_rules` | name, priority, conditions(json), brain_profile_id, fallback_brain_id, enabled |
| `cv_prompt_templates` | key, name, body, variables(json), version, status |
| `cv_brain_health` | brain_profile_id, status, latency_ms, error_rate, checked_at |
| `cv_ai_usage_daily` | date, brain_profile_id, agent_id, channel_type, requests, tokens_in, tokens_out, cost |

`conditions` example:
```json
{"all":[{"field":"language","op":"in","value":["bn","banglish"]},
        {"field":"intent","op":"in","value":["negotiation","complaint"]}]}
```

## 5. Knowledge

| Table | Key columns |
|-------|-------------|
| `cv_collections` | name, description, enabled, local_only |
| `cv_products` (SD) | collection_id, name_bn, name_en, slug, category, short_desc, long_desc, features(json), benefits(json), target_customers, tech, delivery_time, support_terms, demo_url, enabled |
| `cv_product_faqs` | product_id, question, answer, language |
| `cv_price_plans` | product_id, name, price_type (fixed/from/monthly/yearly/one_time/custom), amount, currency, tax_note, features(json), valid_from, valid_to, enabled |
| `cv_offers` | title, description, discount_type, discount_value, coupon_code, conditions, valid_from, valid_to, priority, enabled |
| `cv_offer_product` | offer_id, product_id |
| `cv_company_entries` | key, title, body, language, visibility, enabled |
| `cv_faq_library` | question, answer, tags, enabled |
| `cv_playbooks` | product_id nullable, type (qualification/objection/upsell/closing), body, enabled |
| `cv_knowledge_files` | collection_id, product_id nullable, title, path, mime, size, language, tags, visibility (public/internal), valid_from/to, version, status (uploaded/processing/indexed/failed), error, enabled, uploaded_by |
| `cv_knowledge_file_versions` | file_id, version, path, checksum |
| `cv_knowledge_chunks` | source_type (file/product/faq/offer/company…), source_id, collection_id, content, language, embedding (vector), meta(json), enabled |
| `cv_attachments` | title, path, product_id, description (sendable assets) |
| `cv_knowledge_gaps` | conversation_id, message_id, question, count, status (open/resolved), resolved_by |

## 6. Sales

| Table | Key columns |
|-------|-------------|
| `cv_lead_stages` | name, order, color, is_won, is_lost |
| `cv_lead_events` | contact_id, type (stage_change/score_change/demo_request/quote_request), data(json) |
| `cv_demo_requests` | contact_id, preferred_time, product_id, status, assigned_user_id |
| `cv_quote_requests` | contact_id, requirements, product_ids(json), status |

## 7. Proactive

| Table | Key columns |
|-------|-------------|
| `cv_templates` | name, channel_type, language, body, variables(json), approval_status, external_template_id |
| `cv_triggers` | name, event, conditions(json), action(json), approval_mode (auto/manual), enabled |
| `cv_sequences` / `cv_sequence_steps` | steps with delay, template/AI-mode, channel strategy, stop_conditions(json) |
| `cv_campaigns` | name, segment_id, template_id/sequence_id, schedule_at, status, stats(json) |
| `cv_scheduled_messages` | contact_id, channel_account_id, source (trigger/campaign/sequence), body, send_at, status (pending/approval/sent/failed/cancelled), approval_by, error |
| `cv_consents` | contact_id, channel_type, status (opt_in/opt_out), source, recorded_at |
| `cv_exclusions` | type (phone/contact/tag), value |
| `cv_sms_gateways` | name, provider, credentials(encrypted), sender_id, type (masking/non_masking), balance, status |
| `cv_sms_logs` | scheduled_message_id, gateway_id, to, segments, cost, provider_msg_id, status |

## 8. Key Indexes and Rules

- unique `(channel_account_id, external_id)` on identities; unique message external IDs.
- Index `cv_messages(conversation_id, created_at)`, `cv_conversations(status, last_message_at)`, `cv_scheduled_messages(status, send_at)`.
- Vector index (HNSW) on `cv_knowledge_chunks.embedding`; filter by `enabled` and `collection_id` in every query.
- Enabled flags are mirrored onto chunks when a source is disabled, so retrieval never returns disabled content.
- Offers and prices are read from structured tables at answer time and filtered by validity dates.