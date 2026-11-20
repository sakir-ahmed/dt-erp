# dt-erp — AI Engine Design (M01 DT-Converse)

## 1. Reply Pipeline (per customer turn)

1. **Ingest and debounce:** merge rapid messages (2–4 s window), dedupe, resolve contact.
2. **Gate checks:** kill switch, conversation mode (human active → stop), agent working hours, opt-out/stop words.
3. **Language detection** (Bangla script / English / Banglish) per message, plus customer preference.
4. **Understanding step (lightweight):** intent, sentiment, topic/product mention, extracted slots (name, phone, budget…). Output as strict JSON.
5. **Agent selection:** current agent or Router agent → specialist.
6. **Retrieval:**
   - Structured lookup: current topic product, active price plans, valid offers (by dates and enabled).
   - Semantic search over enabled chunks in the agent's collections (hybrid: vector + keyword), query rewritten with topic and history so "ar price koto?" becomes "price of <current product>".
7. **Brain routing:** evaluate rules (priority order) → brain profile; apply local-only constraint, budget caps, health status.
8. **Prompt assembly** (see §4) → generate.
9. **Guardrails:** price/offer verification against structured data, forbidden claims, internal-only leakage, language check, length and tone, confidence score.
10. **Decision:** send / ask clarifying question / hand off.
11. **Persist** message and trace; update context, summary, lead score, stage; emit events; send via outbound queue.

## 2. Language Rules

| Customer writes | Reply style |
|-----------------|-------------|
| Bangla script | Natural Bangla script, polite "আপনি" form, technical terms may stay in English |
| Banglish (Latin letters) | Banglish, simple and friendly; do not switch to Bangla script |
| English | English |
| Mixed | Follow the dominant language of the latest message |

- Detection heuristics: Unicode script ratio, then Banglish lexicon (common words like *koto, ache, lagbe, kemon, dam, bhai, apni*) plus a small classifier call on ambiguous text.
- Preference override: customer request or agent/channel forced language wins.
- Numbers and currency follow the customer's style (৳ / Tk / BDT; Bangla digits only if the customer used them).
- Knowledge may be in any language; the answer is always written in the customer's language.

## 3. Brain Routing

### 3.1 Inputs available to rules
`agent`, `channel`, `language`, `intent`, `stage`, `lead_score`, `message_length`, `complexity` (from understanding step), `time_of_day`, `queue_load`, `daily_budget_used`, `local_only`, `turn_type` (understanding / generation / summary / proactive).

### 3.2 Suggested default policy (editable in UI)

| Priority | Condition | Brain |
|----------|-----------|-------|
| 1 | local_only = true | Ollama (best Bangla-capable model) |
| 2 | turn_type = understanding or summary | Ollama small/fast model |
| 3 | intent in (greeting, simple FAQ) | Ollama |
| 4 | intent in (negotiation, complaint, high-value lead) | OpenAI strong model |
| 5 | language in (bn, banglish) and complexity high | OpenAI |
| 6 | proactive personalized message | OpenAI mini or Ollama (by config) |
| default | everything else | Agent's assigned brain |

### 3.3 Fallback and resilience
- Timeout/error → retry once → fallback brain (blocked if local-only).
- Output fails guardrails or confidence is low → regenerate on stronger brain once → else clarifying question or handoff.
- If all brains down → acknowledgement message plus handoff or queued reply; alert admins.
- Circuit breaker per brain using health stats; budget cap reached → route to local or stop per setting.

### 3.4 Embeddings
Single embedding model per index (OpenAI or local). Changing the model requires full re-index (guarded action).

## 4. Prompt Structure

Layers, in order:

1. **System base:** identity (Datatech BD Ltd. assistant), honesty rules, no invention, no secrets, AI transparency.
2. **Agent persona:** role, tone, sales aggressiveness, guardrails, allowed actions.
3. **Language instruction:** target language/style from detection.
4. **Business facts block (structured):** active product(s), plans with prices, valid offers, company info. Marked as the only source of truth for prices.
5. **Retrieved knowledge chunks:** delimited, labeled with source IDs, marked as reference data (never instructions).
6. **Conversation state:** rolling summary, slots known, missing qualification info, funnel stage, next-best-action hint.
7. **Recent turns** (last N messages).
8. **Output contract:** JSON `{reply, intent, confidence, used_sources[], slots_update{}, next_action, handoff{needed, reason}}`.

Prompt templates are versioned in `cv_prompt_templates`; the version used is stored in the trace.

## 5. Sales Behavior Logic

- **Next-best-action** chosen from: ask qualifying question, give benefit, mention relevant offer, propose demo, request contact details, share attachment, hand off.
- **Qualification slots:** need, business type/size, budget range, timeline, decision maker, phone, email. Ask one at a time, only when natural.
- **Offer mention rule:** only offers valid today, linked to the current topic product, max once per few turns unless asked.
- **Objection handling:** retrieve matching playbook entry, adapt, never pressure; offer human help.
- **Lead scoring (starter formula):** intent weight + budget stated + timeline soon + contact shared + engagement speed + demo/quote request; threshold configurable for "hot lead" alert.
- **Discount authority:** agents may only quote offers in data; any extra request triggers handoff.

## 6. Guardrails and Safety

- **Price check:** every currency amount in the reply must match an active price/offer; otherwise rewrite or handoff.
- **Grounding check:** product claims need supporting chunk or structured field; unsupported claims removed or answered with "I will confirm with our team."
- **Internal-only content** never included; keyword and source-visibility filter.
- **Prompt injection:** ignore instructions inside customer text or knowledge; refuse to reveal prompts/keys; strip links to unknown domains unless in allowlist.
- **Topic control:** off-topic or abusive → polite deflection; repeated abuse → handoff or stop.
- **Human request detection** in all three languages ("manush er sathe kotha bolbo", "human agent chai", "talk to a person").
- **Opt-out detection:** stop/unsubscribe phrases in Bangla, English, Banglish.

## 7. Knowledge Ingestion

1. Upload → store file, create version record.
2. Parse by type (md/txt direct; pdf/docx text extraction; csv/xlsx row-wise; json mapped).
3. Markdown-aware chunking by headings (target 300–600 tokens, small overlap), keep heading path in metadata.
4. Detect language per chunk, attach metadata (collection, product, tags, visibility, validity).
5. Embed in batches (`knowledge` queue), upsert to vector index, mark file `indexed`.
6. Enable/disable and validity dates are enforced at query time and by chunk flags.
7. Failures recorded with reason; admin can retry.

## 8. Proactive Message Generation

- Inputs: trigger, contact summary, last topic, last language, relevant valid offer, template constraints (WhatsApp templates are fixed text with variables; free-form AI text only inside allowed windows or on SMS).
- Safety checks before queueing: consent, quiet hours (default 21:00–09:00 BST), frequency caps, exclusion lists, channel policy window, approval mode.
- SMS: Unicode detection, segment and cost estimate, short and clear, include opt-out hint where required.
- Stop conditions: customer replied, stage won/lost, opt-out, human took over.

## 9. Quality and Evaluation

- **Golden test set:** 150+ real-style questions across Bangla/Banglish/English covering products, prices, offers, objections, off-topic; run on every prompt/brain change.
- Metrics: grounded accuracy, price correctness, language match rate, handoff precision, latency, cost per conversation.
- Staff feedback (thumbs and reasons) feeds a review queue.
- Playground shows full trace (route decision, chunks, brain, tokens) for tuning.

## 10. Observability

Per AI call log: brain, model, tokens, latency, route reason, fallback flag, guardrail outcome. Dashboards for latency percentiles, error rate, fallback rate, spend vs budget, knowledge hit rate, handoff reasons.