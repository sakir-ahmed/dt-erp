# dt-erp — Product Requirements Document (PRD)

**Project:** dt-erp (Datatech BD Ltd. Enterprise Resource Platform)
**Module in this document:** M01 — AI Social Messaging & Sales Automation (code name: **DT-Converse**)
**Document version:** 1.0 (Draft for review)
**Date:** 19 November 2026
**Owner:** Datatech BD Ltd.
**Repository model:** Single repo (monorepo) — Laravel backend + Vue.js frontend
**Status:** Planning

---

## 1. Purpose of This Document

This PRD defines the business goals, features and requirements of the first module of dt-erp: an AI-driven social media messaging automation system. It describes **what** the system must do and **why**. It does not prescribe detailed implementation. Future dt-erp modules (CRM, Billing, HR, Projects, Support, etc.) will be added later, so this module must be built as a self-contained, loosely coupled module that can plug into the ERP core.

---

## 2. Background and Problem Statement

Datatech BD Ltd. is a software development company that sells multiple products and services (software, custom development, hosting, support packages, etc.). Most inbound leads arrive through social media and messaging platforms (Facebook Messenger, WhatsApp, Instagram, etc.), mostly from Bangladeshi customers who write in **Bangla, English, or Banglish** (Bangla written in English letters).

Current problems:

1. Slow or inconsistent replies, especially after office hours, which causes lost leads.
2. Agents give inconsistent information about product features, price and offers.
3. No consistent sales-oriented conversation flow; leads go cold.
4. No structured follow-up based on conversation history.
5. No central record of customer conversations, interest and intent.
6. Product and price knowledge lives in people's heads and scattered files.

---

## 3. Vision and Goals

### 3.1 Vision
A configurable, multi-agent AI assistant layer that talks to customers in their own language, answers accurately from the company's knowledge base, drives the conversation toward a sale, and proactively follows up, while humans stay in control.

### 3.2 Business Goals

| # | Goal | Success Indicator |
|---|------|-------------------|
| G1 | Reply to every incoming message instantly, 24/7 | First response time under 10 seconds for AI-handled chats |
| G2 | Increase lead-to-sale conversion | +25% conversion vs. baseline within 6 months |
| G3 | Reduce manual agent workload | 60%+ of conversations resolved without human involvement |
| G4 | Give accurate, consistent product/price/offer information | Under 2% of audited answers contain wrong price/offer |
| G5 | Re-engage cold and past leads automatically | 10%+ of follow-ups produce a reply |
| G6 | Make everything admin-configurable without developers | New product or offer can go live with no code change |

### 3.3 Non-Goals (Out of Scope for This Module)
- Full CRM, invoicing, accounting, HR, or project management (future dt-erp modules).
- Voice call automation (phone call bots).
- Public marketing website or e-commerce checkout.
- Training or fine-tuning of custom LLMs.
- Social media post publishing / ad management.

---

## 4. Users and Personas

| Persona | Description | Key Needs |
|---------|-------------|-----------|
| **Customer / Lead** | Person messaging Datatech BD on Facebook, WhatsApp, etc. in Bangla, English or Banglish | Fast, correct, friendly answers in own language; price and offer clarity; easy path to buy or talk to a human |
| **Super Admin** | Datatech BD technical/owner-level admin | Full configuration, AI provider setup, security, audit |
| **Sales Manager** | Manages the sales team and campaigns | Dashboards, lead quality, agent configuration, follow-up campaigns |
| **Sales / Support Agent** | Human who takes over chats | Unified inbox, full chat history, AI suggestions, quick handoff |
| **Knowledge Manager** | Maintains product and company information | Easy upload and editing of knowledge, versioning, enable/disable |
| **Marketing Executive** | Runs proactive SMS and follow-up campaigns | Segments, templates, scheduling, delivery reports |

---

## 5. Scope Overview

The module consists of the following functional areas:

1. Channel Integration and Unified Inbox
2. Conversation Engine (language, context, topic management)
3. Multi-Agent System
4. AI Brain Management and Routing (OpenAI/ChatGPT API and local Ollama)
5. Knowledge Base Management (products, services, prices, offers, company info)
6. Sales-Driven Conversation Features
7. Human Handoff and Live Agent Console
8. Proactive Messaging and SMS Automation
9. Lead and Customer Profile Management
10. Analytics and Reporting
11. Administration, Roles, Security and Audit
12. Configuration and Feature Toggles

---

## 6. Functional Requirements

Priority legend: **P0** = must-have for MVP, **P1** = important, release soon after MVP, **P2** = future/nice-to-have.

### 6.1 Channel Integration and Unified Inbox

| ID | Requirement | Priority |
|----|-------------|----------|
| CH-01 | Connect Facebook Page Messenger and receive and send messages | P0 |
| CH-02 | Connect WhatsApp (Business API) | P0 |
| CH-03 | Connect Instagram Direct Messages | P1 |
| CH-04 | Connect SMS gateway(s) for outbound SMS and, where supported, inbound replies | P0 |
| CH-05 | Website live-chat widget, embeddable on Datatech BD websites | P1 |
| CH-06 | Telegram and email channel | P2 |
| CH-07 | Support multiple accounts per channel (e.g., several Facebook pages, several WhatsApp numbers) | P0 |
| CH-08 | Unified inbox showing all channels in one place with channel badge, filters and search | P0 |
| CH-09 | Handle media: images, documents, voice notes, stickers/emoji (store; AI handles text; voice-to-text is P2) | P1 |
| CH-10 | Respect channel rules (e.g., WhatsApp 24-hour window and approved templates; Messenger messaging policy) and warn admins when a send would violate them | P0 |
| CH-11 | Show message delivery status (sent, delivered, read, failed) where the platform provides it | P1 |
| CH-12 | Support comment-to-inbox replies (reply to Facebook/Instagram post comments and move the person to inbox) | P2 |

### 6.2 Conversation Engine

#### 6.2.1 Language Handling

| ID | Requirement | Priority |
|----|-------------|----------|
| LG-01 | Automatically detect the customer's language per message: **Bangla (বাংলা), English, Banglish** | P0 |
| LG-02 | Reply in the same language and style as the customer by default (Bangla→Bangla, Banglish→Banglish, English→English) | P0 |
| LG-03 | Handle mixed-language messages in one chat (e.g., switching from Banglish to English) and adapt on the next reply | P0 |
| LG-04 | Understand common Banglish spelling variations, shortcuts and informal writing (e.g., "price koto", "software ta ki") | P0 |
| LG-05 | Admin can set a default language and force a language per agent, per channel or per customer | P1 |
| LG-06 | Customer can ask to switch language ("English e bolen"), and the system remembers the preference | P1 |
| LG-07 | Tone control per agent: formal, friendly, persuasive; polite Bangla forms (আপনি) by default | P1 |
| LG-08 | Numbers, currency (BDT / ৳) and dates are formatted correctly for the language used (including Bangla digits when the customer uses them) | P1 |

#### 6.2.2 Context, Memory and Topic Management

| ID | Requirement | Priority |
|----|-------------|----------|
| CT-01 | Maintain the full conversation history per customer, across sessions and channels where the customer is identified | P0 |
| CT-02 | Keep track of the **current topic** (e.g., ERP software, mobile app development, hosting) so follow-up questions like "ar price koto?" refer to the right product | P0 |
| CT-03 | Detect topic switches and update the context; return to the previous topic when the customer asks | P0 |
| CT-04 | Maintain a short-term memory (recent turns) and a long-term memory summary (customer needs, budget, objections, past purchases/inquiries) | P0 |
| CT-05 | Automatically summarize long conversations to keep context efficient | P1 |
| CT-06 | Detect customer intent: product inquiry, price inquiry, demo request, complaint, support issue, purchase intent, general company query, spam, human request | P0 |
| CT-07 | Detect sentiment (positive, neutral, negative/angry) and escalate to a human when needed | P1 |
| CT-08 | Never lose context when a conversation is handed from AI to human or between agents | P0 |
| CT-09 | Handle repeated messages, duplicates, and rapid multi-message bursts (combine into one reply) | P1 |

### 6.3 Multi-Agent System

An **AI Agent** is a configurable virtual assistant persona with its own role, instructions, knowledge scope, language/tone, AI brain, and allowed actions. Multiple agents can run at the same time.

| ID | Requirement | Priority |
|----|-------------|----------|
| AG-01 | Admin can create, edit, clone, enable and disable any number of AI agents | P0 |
| AG-02 | Each agent has: name, avatar, role (e.g., Sales Agent, Support Agent, Pre-sales Consultant, Billing Info Agent), system instructions/persona, tone, default language, greeting, fallback message | P0 |
| AG-03 | Each agent is linked to a **knowledge scope** (which products, services, knowledge collections it may use) | P0 |
| AG-04 | Each agent is assigned an **AI brain profile** (see 6.4) and optional fallback brain | P0 |
| AG-05 | Assign agents to channels and accounts (e.g., Agent A on Facebook Page 1, Agent B on WhatsApp) | P0 |
| AG-06 | **Router / Orchestrator agent:** a first-contact agent that identifies intent and passes the chat to the best specialist agent (sales, support, billing, product-specific) | P1 |
| AG-07 | Agent-to-agent handoff inside a conversation, preserving context, with the customer seeing a smooth transition | P1 |
| AG-08 | Working hours and schedules per agent (e.g., AI covers after-hours; humans during office hours) | P1 |
| AG-09 | Per-agent guardrails: topics to avoid, forbidden claims, maximum discount authority, mandatory disclaimers | P0 |
| AG-10 | Per-agent allowed actions: collect lead info, share price, share offer, send brochure/file, book demo, create ticket, handoff to human | P1 |
| AG-11 | Test console (playground) for admins to chat with an agent before enabling it, showing which knowledge and brain were used | P0 |
| AG-12 | Versioning of agent configuration with rollback | P1 |
| AG-13 | Agent-level performance statistics (conversations, handoff rate, conversions) | P1 |

### 6.4 AI Brain Management and Routing

The module must support **multiple AI providers**: the **OpenAI (ChatGPT) API** and a **local Ollama** server already running in the company infrastructure. Admins decide, per scenario and configuration, which brain is used.

| ID | Requirement | Priority |
|----|-------------|----------|
| AB-01 | Register multiple **AI brain profiles**, each with: provider (OpenAI or Ollama), model name, endpoint, credentials (encrypted), temperature, max tokens, timeout, cost per token (optional), enable/disable | P0 |
| AB-02 | Support several OpenAI models and several Ollama models at the same time (e.g., a cheap fast model, a stronger reasoning model, a Bangla-capable model) | P0 |
| AB-03 | **Routing rules** engine: choose the brain by configurable conditions such as: agent, channel, language, intent, conversation stage, customer value/lead score, message complexity, time of day, current load, and cost budget | P0 |
| AB-04 | Example default policies (editable): simple FAQ/greeting → local Ollama; complex sales negotiation, long Bangla writing, or low-confidence cases → OpenAI; sensitive data → local only | P0 |
| AB-05 | **Automatic fallback:** if the primary brain fails, times out, or returns low-quality output, retry on a secondary brain | P0 |
| AB-06 | **Data privacy mode:** mark certain data/conversations/agents as "local-only" so nothing is sent to external APIs | P0 |
| AB-07 | Health check and status dashboard for each brain (online/offline, latency, error rate) | P1 |
| AB-08 | Usage and cost tracking per brain, agent, channel and day, with budget limits and alerts | P1 |
| AB-09 | Per-conversation log of which brain/model answered each message (for audit and quality review) | P0 |
| AB-10 | Rate limiting and queueing so spikes in messages do not overload any brain | P0 |
| AB-11 | Optional A/B testing of two brains or two prompt versions with performance comparison | P2 |
| AB-12 | Prompt templates managed from the admin panel (system prompts, sales playbook prompts, language rules) with variables and versioning | P1 |
| AB-13 | Embedding model configuration for knowledge search (OpenAI embeddings and/or a local embedding model) | P0 |

### 6.5 Knowledge Base Management

The knowledge base must be **fully dynamic, configurable, and manageable from the UI**. It covers multiple products/services of Datatech BD Ltd., pricing, offers and general company information.

#### 6.5.1 Knowledge Structure

| ID | Requirement | Priority |
|----|-------------|----------|
| KB-01 | **Product/Service catalog:** each item has name (Bangla and English), category, short and long description, features, benefits, target customers, technology, delivery timeline, support terms, FAQs, demo link, brochure files, status (active/inactive) | P0 |
| KB-02 | **Pricing:** multiple price plans/packages per product, price type (fixed, starting from, monthly, yearly, one-time, custom quote), currency, tax/VAT note, validity dates | P0 |
| KB-03 | **Offers and promotions:** title, description, linked products, discount type/value, start and end date, eligibility conditions, coupon code, priority; automatically expire | P0 |
| KB-04 | **Company information:** about Datatech BD Ltd., address, contact numbers, office hours, social links, clients/portfolio, certifications, payment methods, policies | P0 |
| KB-05 | **FAQ and objection handling library:** common questions and recommended answers (e.g., "price beshi", "ager company ki dichhe") | P1 |
| KB-06 | **Competitor/comparison notes** (internal-only; marked so agents use them carefully) | P2 |
| KB-07 | **Sales playbooks:** qualification questions, upsell/cross-sell rules, closing scripts per product | P1 |
| KB-08 | Organize knowledge in **collections** (e.g., "ERP Products", "Web Development", "Company General") and assign collections to agents | P0 |

#### 6.5.2 Knowledge Files and Ingestion

| ID | Requirement | Priority |
|----|-------------|----------|
| KF-01 | Upload knowledge files, with **.md (Markdown)** as the primary format; also **.txt, .pdf, .docx, .csv, .xlsx, .json** | P0 |
| KF-02 | Bulk upload (multiple files, drag-and-drop) and folder-like organization | P1 |
| KF-03 | Built-in Markdown editor/preview to create and edit knowledge directly in the system | P1 |
| KF-04 | Automatic processing after upload: extract text, split into chunks, create embeddings, index for semantic search; show processing status and errors | P0 |
| KF-05 | Support Bangla, English and mixed-language documents; searching works across languages (a Banglish question can find an English document) | P0 |
| KF-06 | Metadata per file/entry: collection, linked product/service, language, tags, valid-from/valid-to, owner, visibility (public to customer / internal only) | P0 |
| KF-07 | **Enable/disable** any file, entry, product, offer or collection instantly; disabled items are never used in answers | P0 |
| KF-08 | Versioning: keep previous versions of a file, show differences, restore | P1 |
| KF-09 | Re-index on change automatically; manual "re-index all" option | P0 |
| KF-10 | Import products and prices from CSV/Excel templates | P1 |
| KF-11 | Source linking: an AI answer can be traced to the knowledge source used (visible to admins and agents, not customers) | P0 |
| KF-12 | Attachment library: brochures, price lists, proposals, demo videos or links that AI agents can send to customers on request | P1 |
| KF-13 | Optional website/URL import of public pages as knowledge (admin approved) | P2 |
| KF-14 | Detect knowledge gaps: list of customer questions the AI could not answer, so Knowledge Managers can add content | P1 |
| KF-15 | Knowledge approval workflow (draft → review → published) | P2 |

#### 6.5.3 Answer Accuracy Rules

| ID | Requirement | Priority |
|----|-------------|----------|
| AC-01 | AI must answer product, price, and offer questions **only from active knowledge** (retrieval-grounded). If information is missing, it must say so and offer a human follow-up instead of inventing | P0 |
| AC-02 | Prices and offers must always come from structured price/offer data, not from free text guesses, and must respect validity dates | P0 |
| AC-03 | If knowledge sources conflict, prefer the most recent, highest-priority source and flag the conflict to admins | P1 |
| AC-04 | A configurable **confidence threshold:** below it, the AI asks a clarifying question or hands off to a human | P0 |
| AC-05 | The AI must not promise discounts, delivery dates or custom features outside configured rules | P0 |

### 6.6 Sales-Driven Conversation Features

| ID | Requirement | Priority |
|----|-------------|----------|
| SD-01 | Configurable **sales funnel stages** (New → Engaged → Qualified → Proposal → Negotiation → Won/Lost) updated automatically from conversation signals and manually by staff | P0 |
| SD-02 | **Lead qualification:** AI asks and records need, budget range, timeline, company size, decision-maker, contact details, using natural conversation (not a form) | P0 |
| SD-03 | Every AI reply should, where appropriate, move the customer forward: relevant question, benefit highlight, offer mention, or clear next step (demo, call, quotation, payment) | P0 |
| SD-04 | **Smart recommendation:** suggest the right product/package based on customer needs; suggest upsell/cross-sell | P1 |
| SD-05 | **Offer awareness:** mention currently valid offers relevant to the customer's interest, with urgency details (end date) when real | P0 |
| SD-06 | **Objection handling** (price too high, need to think, competitor, trust) using playbook content | P1 |
| SD-07 | **Demo/meeting booking:** collect preferred time and notify the sales team (calendar integration is P2) | P1 |
| SD-08 | **Quotation request capture:** collect requirements and create a quotation request for the sales team (full quotation generation belongs to a future dt-erp module) | P1 |
| SD-09 | **Lead scoring** based on engagement, intent, budget, and response speed; hot leads trigger an instant alert to a human | P1 |
| SD-10 | Collect and validate phone number and email in conversation | P0 |
| SD-11 | Conversation tone must remain honest and non-pushy; configurable "sales aggressiveness" level per agent | P1 |
| SD-12 | Payment information sharing (bank/mobile banking details) from approved company data only | P1 |
| SD-13 | Post-sale messages: onboarding info, thank-you, feedback request | P2 |

### 6.7 Human Handoff and Live Agent Console

| ID | Requirement | Priority |
|----|-------------|----------|
| HH-01 | Customer can request a human at any time; AI must comply promptly | P0 |
| HH-02 | Automatic handoff triggers: low confidence, negative sentiment, complaint, high-value lead, repeated failure, restricted topic, configured keywords | P0 |
| HH-03 | **Takeover/Release:** human agent can pause AI in a conversation and later hand it back; AI never replies while a human is active | P0 |
| HH-04 | Assignment rules: round-robin, by skill/product, by workload, by availability, manual assign | P1 |
| HH-05 | Agent console shows the full history, AI summary, customer profile, detected intent, lead score, and knowledge used | P0 |
| HH-06 | **AI-assisted replies:** suggested reply drafts the human can edit and send | P1 |
| HH-07 | Internal notes and @mentions inside a conversation (not visible to customers) | P1 |
| HH-08 | Canned responses/quick replies with variables, in Bangla and English | P1 |
| HH-09 | Conversation statuses: open, pending, snoozed, resolved; tags and labels | P0 |
| HH-10 | Notifications (in-app, email, optional push) for new assignments, hot leads and escalations | P1 |
| HH-11 | SLA timers (first-response and resolution) with escalation alerts | P2 |
| HH-12 | Auto-return to AI if a human does not respond within a configurable time (optional) | P2 |

### 6.8 Proactive Messaging and SMS Automation

| ID | Requirement | Priority |
|----|-------------|----------|
| PM-01 | **Follow-up automation based on history:** e.g., customer asked price but did not reply for 24 hours → send a polite follow-up | P0 |
| PM-02 | Configurable **triggers:** inactivity period, funnel stage change, offer published/about to expire, demo scheduled/missed, quotation sent, birthday/anniversary, product update, contract/renewal date (from future modules), cart-like "interest shown but no action" | P0 |
| PM-03 | **AI-personalized message generation** using customer history, interest, and language (messages in Bangla, English or Banglish matching the customer's last used language) | P0 |
| PM-04 | **SMS** sending via configured gateway(s), with Bangla (Unicode) SMS support, length/segment calculation and cost estimate | P0 |
| PM-05 | Multi-step **sequences/drip campaigns** (e.g., Day 1 message, Day 3 offer reminder, Day 7 final follow-up) with stop conditions when the customer replies, buys or opts out | P1 |
| PM-06 | **Bulk campaigns** to customer segments (by product interest, stage, source, tags, last contact date) with scheduling | P1 |
| PM-07 | **Message templates** with variables, language variants, approval status (needed for WhatsApp), and preview | P0 |
| PM-08 | **Consent and opt-out management:** respect STOP/unsubscribe requests in Bangla/English/Banglish (e.g., "বন্ধ করুন", "stop", "bondho koro"), store consent, never message opted-out contacts | P0 |
| PM-09 | **Sending rules:** quiet hours (no night messages), daily/weekly frequency caps per customer, time-zone aware (Bangladesh Standard Time default), best-time-to-send option (P2) | P0 |
| PM-10 | Compliance with Bangladesh telecom rules (BTRC), e.g., masking/non-masking sender ID and promotional vs. transactional SMS rules, and with platform policies | P0 |
| PM-11 | **Approval mode:** configurable "send automatically" vs. "send after human approval" for proactive messages | P0 |
| PM-12 | Delivery reports, failure reasons, retry rules, and a campaign results dashboard (sent, delivered, replied, converted) | P1 |
| PM-13 | When a customer replies to a proactive message, the conversation continues seamlessly with the AI agent or human, with campaign context included | P0 |
| PM-14 | Smart **channel selection:** choose the best channel for a follow-up (e.g., WhatsApp if available, else SMS) according to rules | P1 |
| PM-15 | Support SMS wallet/balance monitoring and low-balance alerts | P2 |
| PM-16 | Exclusion lists (existing customers, do-not-contact, internal numbers) | P1 |

### 6.9 Lead and Customer Profile Management

| ID | Requirement | Priority |
|----|-------------|----------|
| CP-01 | Automatic creation of a contact profile for every new person who messages | P0 |
| CP-02 | Profile fields: name, phone, email, company, designation, location, channel IDs, language preference, tags, source, interests, budget, lead score, stage, owner, consent status | P0 |
| CP-03 | **Identity merge:** link the same person across Facebook, WhatsApp and SMS (by phone/email, manually or suggested) and merge duplicates | P1 |
| CP-04 | Timeline view of all messages, notes, funnel changes, campaigns and actions | P0 |
| CP-05 | AI-generated customer summary ("what they want, objections, next best action") | P1 |
| CP-06 | Segmentation with saved filters used by campaigns and reports | P1 |
| CP-07 | Import/export contacts (CSV/Excel), with consent flag | P1 |
| CP-08 | Custom fields defined by admins | P2 |
| CP-09 | Data subject requests: view, export, delete customer data on demand | P1 |
| CP-10 | Provide contact data to other dt-erp modules through a clean internal interface (so a future CRM module can reuse it) | P1 |

### 6.10 Analytics and Reporting

| ID | Requirement | Priority |
|----|-------------|----------|
| AN-01 | Dashboard: total conversations, new leads, AI-resolved %, handoff %, average first response time, conversion rate, revenue influenced (if linked) | P0 |
| AN-02 | Channel, agent, product, and campaign-level reports | P1 |
| AN-03 | Sales funnel report with drop-off analysis | P1 |
| AN-04 | Top asked questions, top products of interest, top objections | P1 |
| AN-05 | Knowledge gap report (unanswered questions), most-used and never-used knowledge items | P1 |
| AN-06 | AI quality review: sampled conversations, thumbs up/down by staff, flagged answers, accuracy audit | P1 |
| AN-07 | AI cost and token usage report per brain, agent and day | P1 |
| AN-08 | Language distribution report (Bangla / English / Banglish share) | P2 |
| AN-09 | Export reports to CSV/Excel/PDF; scheduled email reports | P2 |
| AN-10 | Date range, channel, agent and product filters on all reports | P0 |

### 6.11 Administration, Roles, Security and Audit

| ID | Requirement | Priority |
|----|-------------|----------|
| AD-01 | Role-based access control with default roles: Super Admin, Sales Manager, Agent, Knowledge Manager, Marketing, Viewer; custom roles and granular permissions | P0 |
| AD-02 | Team and user management, with login, password policy and optional two-factor authentication | P0 |
| AD-03 | Complete **audit log:** who changed which agent, knowledge, price, brain config, campaign, with timestamps | P0 |
| AD-04 | Encrypted storage of API keys, tokens and secrets; secrets never shown in full after saving | P0 |
| AD-05 | Sensitive data masking in logs (phone numbers, payment details) and option to mask in UI by role | P1 |
| AD-06 | Global **kill switch:** instantly pause all AI auto-replies, all proactive messages, or a single channel/agent | P0 |
| AD-07 | Business hours, holidays and out-of-office messages | P1 |
| AD-08 | Multi-branch / multi-brand readiness (data separation by brand/company) for future use | P2 |
| AD-09 | Data retention settings per data type (messages, media, logs) | P1 |
| AD-10 | System health page: queue status, webhook status, channel connection status, brain status | P1 |

### 6.12 Configuration and Feature Toggles

| ID | Requirement | Priority |
|----|-------------|----------|
| CF-01 | Every major feature has an **enable/disable** switch at global, channel, agent or product level (auto-reply, proactive messaging, SMS, handoff rules, lead scoring, brain routing, offers, etc.) | P0 |
| CF-02 | Settings are changeable from the admin UI without code deployment, and take effect quickly | P0 |
| CF-03 | Configuration export/import (agents, routing rules, templates) to move between staging and production | P2 |
| CF-04 | Sandbox/test mode: run the full flow without sending real messages | P1 |
| CF-05 | Multi-language admin UI (English first; Bangla UI is P2) | P2 |

---

## 7. Key User Journeys

### 7.1 New lead on Facebook Messenger (Banglish)
1. Customer: *"ERP software er price koto?"*
2. System detects Banglish and the intent "price inquiry"; the router chooses the Sales agent; brain routing selects the configured brain.
3. Agent retrieves ERP product and active price plans and any valid offer, then replies in Banglish with price information and one qualifying question (e.g., about business size).
4. Customer answers; profile and lead score update; funnel stage moves to *Qualified*.
5. Customer says *"demo dekhte chai"*; the agent collects name, phone and preferred time, then notifies the sales team and confirms with the customer.

### 7.2 Topic switch
Customer asks about the mobile app, then asks *"hosting er ki offer ache?"* The topic switches to hosting; the agent answers from hosting knowledge only; if the customer later says *"app er price ta bolo"*, the context returns to the mobile app.

### 7.3 Unknown question / handoff
Customer asks something outside the knowledge. The AI states it does not have confirmed information, offers a human follow-up, records the question in the knowledge-gap list, and (if configured) hands off to a human with a summary.

### 7.4 Proactive follow-up
A lead asked for a price and went silent for 24 hours. The system checks consent, quiet hours and frequency caps, generates a personalized message in the customer's last used language, and sends it by WhatsApp, or by SMS if WhatsApp is unavailable, either automatically or after manager approval.

### 7.5 Offer launch
Marketing creates a new offer, links it to two products, sets dates and enables it. Agents begin mentioning it in relevant chats; a segmented SMS/WhatsApp campaign goes to interested leads; the offer is automatically removed from answers after its end date.

### 7.6 Admin adds a new product
Knowledge Manager creates a product, uploads `.md` files and a brochure, defines price plans, adds it to a collection, assigns the collection to an agent and enables it. After indexing finishes, the playground test passes and the product goes live, with no developer involved.

---

## 8. Data Concepts (High-Level)

Main business entities (details to be defined in technical design):

- **Channel Account** — a connected page/number/gateway
- **Contact (Lead/Customer)** and **Contact Identity** (per-channel IDs)
- **Conversation** and **Message** (with direction, sender type: customer/AI/human/system)
- **Conversation Context** (current topic, summary, intent, sentiment)
- **AI Agent** and **Agent Version**
- **AI Brain Profile** and **Routing Rule**
- **Prompt Template**
- **Product/Service**, **Price Plan**, **Offer**, **Company Info Entry**, **FAQ Entry**, **Playbook**
- **Knowledge Collection**, **Knowledge File**, **Knowledge Chunk** (for semantic search)
- **Lead Stage**, **Lead Score**, **Task/Follow-up**
- **Template**, **Campaign**, **Sequence**, **Scheduled Message**, **Consent Record**, **Opt-out**
- **User**, **Role**, **Permission**, **Audit Log**
- **Usage/Cost Record**, **Analytics Snapshot**

---

## 9. Non-Functional Requirements

| ID | Category | Requirement |
|----|----------|-------------|
| NF-01 | Performance | AI reply delivered within 10 seconds for 95% of messages (local model) and within 8 seconds (cloud model) under normal load; inbox UI loads under 2 seconds |
| NF-02 | Scalability | Support at least 500 concurrent active conversations and 10,000 messages/day at launch, designed to scale 10x via queues and workers |
| NF-03 | Availability | 99.5% uptime target; if AI is unavailable, messages are still received and queued, with an auto-acknowledgement or human routing |
| NF-04 | Reliability | No message loss: webhook events are stored first, processed asynchronously, with retries and idempotency (no duplicate replies) |
| NF-05 | Security | HTTPS everywhere, encrypted secrets, webhook signature verification, protection against prompt injection and data leakage, OWASP best practices |
| NF-06 | Privacy | Local-only mode for sensitive data; minimal data sent to external AI; consent tracking; compliance with applicable Bangladesh data protection and telecom regulations |
| NF-07 | Auditability | All AI replies traceable to the brain, prompt version and knowledge sources used |
| NF-08 | Usability | Clean, responsive web UI (desktop and tablet; mobile-friendly agent console); Bangla text renders correctly everywhere (Unicode fonts) |
| NF-09 | Real-time | Live inbox updates without page refresh (new messages, typing, assignment) |
| NF-10 | Maintainability | Module built with clear boundaries so other dt-erp modules can reuse shared services (users, roles, contacts, notifications) |
| NF-11 | Extensibility | Adding a new channel, AI provider, or SMS gateway should be possible through a defined integration pattern without redesign |
| NF-12 | Observability | Central logging, error tracking, queue monitoring and alerts for failures |
| NF-13 | Backup | Daily backups of database and knowledge files with tested restore |
| NF-14 | Localization | Full Unicode support; Bangladesh time zone (BST) default; BDT currency default |
| NF-15 | Safety | AI must refuse or deflect abusive, illegal, or off-topic requests politely and must not reveal internal prompts, keys, or internal-only knowledge |

---

## 10. Technical Constraints and Decisions (Given)

- **Backend:** Laravel (PHP) providing API, queues, scheduling and webhooks.
- **Frontend:** Vue.js single-page application.
- **Repository:** Single repository holding both backend and frontend.
- **AI providers:** OpenAI (ChatGPT) API and a locally installed and running **Ollama** server; both must be usable together.
- **Architecture principle:** This is the first module of dt-erp; shared concerns (authentication, users, roles, notifications, settings, audit) should be designed as ERP-core services for future modules.
- Detailed technology choices (database, vector search, queue driver, real-time layer, hosting) are for the technical design document.

---

## 11. Assumptions and Dependencies

1. Datatech BD Ltd. will obtain and maintain official access: Meta (Facebook/Instagram) app review and permissions, WhatsApp Business API provider, and a BTRC-approved SMS gateway with sender IDs.
2. A valid OpenAI API key and budget are available; the Ollama server is reachable from the application server with suitable models installed (including one that handles Bangla acceptably, to be validated).
3. Datatech BD will provide initial product, price, offer and company knowledge content.
4. Customers are informed they may be talking to an AI assistant (transparency statement configurable in greeting).
5. Platform policies (Meta, WhatsApp) may limit proactive messaging; the system must be configurable to follow them.

---

## 12. Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| AI gives wrong price/offer | Customer trust and revenue loss | Structured price/offer data, grounded answers, confidence threshold, audit and review |
| Weak Bangla/Banglish quality in local model | Poor customer experience | Brain routing to OpenAI for Bangla/Banglish when needed; test set and quality scoring; prompt tuning |
| Platform policy or API changes (Meta/WhatsApp) | Channel disruption | Channel abstraction, policy-aware sending rules, monitoring |
| Spam/over-messaging causing bans or complaints | Account suspension, legal issues | Consent, opt-out, frequency caps, quiet hours, approval mode |
| High AI cost | Budget overrun | Cost-aware routing, local model for simple queries, budget alerts and limits |
| Prompt injection or data leakage | Security incident | Input/output filtering, internal-only flags, no secrets in prompts, red-team tests |
| Local server downtime | AI not responding | Automatic fallback to OpenAI (unless local-only mode), queueing, health alerts |
| Poor knowledge quality | Bad answers | Knowledge gap reports, versioning, review workflow, ownership |

---

## 13. Release Plan

### Phase 1 — MVP (Core Auto-Reply)
- Facebook Messenger and WhatsApp channels, unified inbox
- Language detection and matching (Bangla, English, Banglish)
- Context and topic tracking, conversation memory
- Single and multiple agents (manual assignment per channel), playground
- OpenAI + Ollama brain profiles, basic routing rules, fallback, local-only mode
- Knowledge base: products, prices, offers, company info, `.md` and other file upload, enable/disable, indexing
- Lead capture and funnel stages, human handoff and takeover
- Basic follow-up automation (inactivity trigger) with SMS, consent/opt-out, quiet hours
- RBAC, audit log, kill switch, basic dashboard

### Phase 2 — Sales Intelligence
- Router agent and agent-to-agent handoff
- Lead scoring, recommendations, objection handling, playbooks
- Campaigns and drip sequences, segmentation, templates with approval
- Instagram and website chat widget
- Advanced analytics, cost tracking, knowledge gap reports
- AI-suggested replies for human agents, SLA timers
- Identity merge across channels

### Phase 3 — Advanced and ERP Integration
- A/B testing of brains and prompts
- Voice note transcription, comment-to-inbox automation
- Calendar integration for demos, quotation generation hooks
- Integration with future dt-erp modules (CRM, Billing, Projects, Support)
- Multi-brand support, configuration export/import, approval workflow for knowledge
- Bangla admin UI

---

## 14. Success Metrics (KPIs)

| KPI | Target (6 months after launch) |
|-----|-------------------------------|
| First response time (AI) | Under 10 seconds |
| Share of conversations fully handled by AI | 60% or more |
| Lead-to-qualified-lead rate | +20% vs. baseline |
| Lead-to-sale conversion | +25% vs. baseline |
| Answer accuracy (price/offer/product audits) | 98% or more |
| Human handoff satisfaction (staff feedback) | 4/5 or higher |
| Follow-up reply rate | 10% or more |
| Opt-out rate on proactive messages | Under 2% |
| AI cost per conversation | Within agreed budget |
| Knowledge gaps resolved within 7 days | 80% or more |

---

## 15. Acceptance Criteria (MVP Highlights)

1. A message in Bangla, English and Banglish each receives a reply in the matching language, using correct product and price data.
2. Disabling a product, file or offer removes it from AI answers within 1 minute (after re-index where required).
3. An admin can configure a rule such as "Banglish + simple FAQ → Ollama model; negotiation → OpenAI model," and the conversation log shows the brain used for each reply.
4. If the primary brain is offline, the reply is still delivered through the fallback brain (or queued/handed off in local-only mode).
5. A human can take over a chat; the AI stops replying until control is returned.
6. A lead silent for the configured time receives one follow-up within allowed hours, respecting consent and frequency caps; an opt-out message stops all further messages.
7. Uploading a `.md` file with product details makes that information retrievable by the assigned agent after processing, with the source visible in the admin trace.
8. The kill switch stops all AI auto-replies and proactive messages immediately.
9. Every price/offer shown by AI matches the active structured data, and expired offers are never quoted.
10. All configuration changes are recorded in the audit log.

---

## 16. Open Questions

1. Which SMS gateway provider(s) in Bangladesh will be used, and are masking sender IDs already approved?
2. Which WhatsApp Business API provider (official Cloud API or a BSP) will be used?
3. Which Ollama models are installed, and is there a preferred Bangla-capable model?
4. What are the monthly OpenAI budget limits and the data privacy rules for what may be sent to external APIs?
5. What is the exact list of Datatech BD products/services and price plans at launch?
6. Should prices be shown publicly by the AI in every case, or "contact us" for custom-quote products?
7. How many human agents, in which shifts, and what are the working hours and holidays?
8. Is there an existing CRM/contact database to import?
9. Should the AI introduce itself as an AI assistant in the first message (recommended for transparency)?
10. What approval level is required for proactive messages at launch (fully automatic or manager approval)?

---

## 17. Glossary

| Term | Meaning |
|------|---------|
| **Banglish** | Bangla language written in English (Latin) letters, e.g., "apnar software er price koto?" |
| **AI Brain** | A configured AI model connection (OpenAI model or Ollama model) with its settings |
| **Agent** | A configurable AI persona with role, knowledge scope, tone, and brain |
| **Knowledge Collection** | A group of knowledge items assignable to agents |
| **Embedding / Semantic Search** | Technique for finding relevant knowledge by meaning, not only keywords |
| **Handoff** | Transfer of a conversation from AI to a human, or between agents |
| **Proactive Messaging** | Messages sent by the system without the customer messaging first |
| **Kill Switch** | Control to instantly stop automated replies or messages |
| **Local-only mode** | Setting that keeps data processing on the local Ollama server only |

---

*End of document — dt-erp PRD v1.0, Module M01: AI Social Messaging & Sales Automation*