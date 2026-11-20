# DT-Converse development runbook

## Prepare the application

Run these commands from `web-app` on the host that serves Laravel:

```bash
composer install --no-interaction
composer check-platform-reqs
php artisan migrate --force --no-interaction
php artisan db:seed --class=DatabaseSeeder --no-interaction
npm ci
npm run build
```

Start from `.env.example` on a fresh install. Generate `APP_KEY` once and preserve it through releases. It protects stored Page tokens and signed webhook payloads. Use MySQL for the shared web and worker database. Set `DB_CONNECTION=mysql` and supply `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` through your environment or secret store. Use `QUEUE_CONNECTION=database`, a shared `CACHE_STORE=database`, and `DB_QUEUE_RETRY_AFTER=150`. Enable PHP DOM, SimpleXML, and ZIP for Office extraction and install Poppler's `pdftotext` for searchable PDFs.

Serve the Laravel `public/` directory and set `APP_URL` to the externally reachable HTTPS origin. Keep `storage/` and `bootstrap/cache/` writable by the web and worker user. Share a persistent private `storage/app/private` volume between them for knowledge uploads and revisions. If other app features need Laravel’s public storage link, use `php artisan storage:link` for the public disk only. Use `APP_ENV=production` and `APP_DEBUG=false` on an exposed environment. Local quick sign-in requires both `APP_ENV=local` and `APP_DEBUG=true`.

## Manage the workers

Run one supervised process per database queue. The inbound worker validates delivery context and records incoming text, the knowledge worker extracts and indexes uploads, and the AI worker handles delayed reply jobs. For systemd, install a root-owned templated unit such as `dt-converse@.service`, substituting your unprivileged service account and deployment path.

```ini
[Unit]
Description=DT-Converse %i queue worker
After=network-online.target

[Service]
User=www-data
WorkingDirectory=/srv/dt-erp/web-app
ExecStart=/usr/bin/php artisan queue:work database --queue=%i --sleep=1 --timeout=120 --tries=4
Restart=always
RestartSec=2
TimeoutStopSec=130

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now dt-converse@converse-inbound dt-converse@converse-knowledge dt-converse@converse-ai
php artisan queue:restart
```

The database reservation timeout must exceed worker and job timeouts. Restart workers after code or environment changes. On a config-cached release, rebuild the cache with `php artisan config:cache` first, then use `queue:restart` with the same shared cache and verify the supervisor restarted each worker. The interactive inbox, indexing, and automatic replies are webhook and queue driven and have no scheduler dependency.

## Configure Ollama and Meta

- Run Ollama on loopback reachable from **both** the web and worker PHP processes. The app calls `127.0.0.1` on `CONVERSE_OLLAMA_PORT` (default `11434`). Separate containers need a loopback bridge or shared network namespace so PHP can reach that address. Keep Ollama off the public network.
- Set generation and embeddings in **AI Playground → AI providers**, or use defaults from `CONVERSE_OLLAMA_MODEL`, `CONVERSE_OLLAMA_FALLBACK_MODEL`, `CONVERSE_OLLAMA_PORT`, and `CONVERSE_EMBEDDING_MODEL`. The Mac setup was verified with `qwen2.5:1.5b` and `paraphrase-multilingual`. Check `ollama list` and `/api/tags` from the worker runtime. Re-index affected collections after changing the embedding model. Optional OpenAI Playground defaults are `CONVERSE_AI_PRIMARY`, `CONVERSE_AI_ALLOW_CLOUD`, `CONVERSE_OPENAI_MODEL`, and `CONVERSE_OPENAI_API_KEY`. Store any OpenAI key through the provider settings or a secret store. Customer conversation history stays on Ollama.
- In Meta, configure the Page object callback at `https://<your-host>/api/v1/converse/webhooks/meta`. Use the same private verification value for Meta and `CONVERSE_META_VERIFY_TOKEN`. Set `CONVERSE_META_APP_SECRET` to the Meta app signing secret. GET echoes Meta’s verification challenge. POST validates `X-Hub-Signature-256` against the exact request bytes. Expose this path over HTTPS and preserve its request headers and body through the reverse proxy.
- Subscribe the Page to the app’s `messages` webhook field. Use an authorized Page access token and the Page’s messaging task and `pages_messaging` permission. Check the app's Page-management permissions for installing or inspecting the subscription, including `pages_manage_metadata` where required. Complete Meta App Review and the appropriate access level before serving people outside app roles. In **Channels**, register the numeric Page ID and save the Page token using the masked token field. The application stores the token encrypted. Configure `CONVERSE_GRAPH_VERSION` to a version your Meta app supports. [Meta Webhooks for Pages](https://developers.facebook.com/docs/graph-api/webhooks/getting-started/webhooks-for-pages)
## Connect WhatsApp Cloud API

1. In Meta Business Manager or the app’s WhatsApp API setup, identify the **WhatsApp Business Account ID (WABA ID)** and the registered line’s **Phone Number ID**. Confirm that its displayed number matches the intended business line. Use the Phone Number ID in DT-Converse **Channels → Add channel → WhatsApp**. The WABA ID is for account management and subscription. The visible telephone number cannot replace either ID. [Meta WhatsApp Business Account](https://developers.facebook.com/docs/graph-api/reference/whats-app-business-account)
2. Set the WhatsApp Business Account callback to `https://<your-host>/api/v1/converse/webhooks/whatsapp`. Match `CONVERSE_WHATSAPP_VERIFY_TOKEN` to Meta’s verification value and set `CONVERSE_WHATSAPP_APP_SECRET` for the subscribed Meta app. Select the WhatsApp Business Account object’s **messages** field and subscribe the app to the WABA with `POST /{WABA-ID}/subscribed_apps`. Incoming text and outbound status updates arrive under the same field. [Meta WABA subscriptions](https://developers.facebook.com/docs/graph-api/reference/whats-app-business-account/subscribed_apps)
3. Generate a dedicated Cloud API access token for the intended WABA and business phone asset. Use a Meta test token and verified test recipient for initial checks, or a managed token with the relevant asset assignment for ongoing use. Sending requires `whatsapp_business_messaging`. Managing the WABA, subscriptions, or templates can require `whatsapp_business_management`. Save the token in the WhatsApp channel’s masked sending-token field. [Meta WhatsApp Cloud API messages](https://developers.facebook.com/documentation/business-messaging/whatsapp/reference/whatsapp-business-phone-number/message-api)
4. Assign approved knowledge and the agent under **AI Playground → Channel AI setup**. Begin with a verified tester sending a new text message. Check the contact name and the separate **Accepted**, **Sent**, **Delivered**, and **Read** states in the Inbox. A signed **Failed** receipt can resolve a previously uncertain send. An HTTP acceptance alone does not confirm delivery. [Meta WhatsApp delivery status webhooks](https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/reference/messages/status/)
5. DT-Converse sends manual and AI service-text replies during the 24-hour inbound window. Outside that window, Meta requires a pre-approved template. DT-Converse does not currently submit templates, so the composer blocks free-form sends and waits for a new inbound message. Use only recipients who have opted in, and honor opt-outs. [Meta WhatsApp service messages](https://developers.facebook.com/documentation/business-messaging/whatsapp/messages/send-messages/)

## Enable grounded replies

1. In **Knowledge**, use an enabled collection. Upload approved content, wait for **Indexed**, then mark the document enabled and customer-visible. Check validity dates and use structured entries for prices or offers.
2. In **AI Playground → Channel AI setup**, select the Page and assign the approved collection. If the agent has a restricted collection list, it must include that collection too.
3. Set `CONVERSE_OUTBOUND_ENABLED=true` to allow delivery and `CONVERSE_AI_AUTO_REPLY=true` to allow automatic replies. Reload cached configuration and restart the workers. In channel setup, allow auto-reply for the selected Page. In the Inbox, set eligible conversations to **AI**. A Human, Paused, resolved, snoozed, or opted-out thread cannot receive an automatic reply. The app checks the current 24-hour inbound reply window and de-duplicates provider events and reply operations.

## Smoke test and diagnose

- Keep outbound delivery and auto-reply off for setup checks. Confirm `/up`, poll the private Ollama endpoint, index a fictional document in an isolated collection, and run an authorized Playground test with synthetic text. Never associate fictional sources with a customer-facing channel. If the optional Playground fixture is unavailable, use the automated fixture.
- From `web-app`, run `vendor/bin/pest tests/Feature/ConverseGroundedWebhookTest.php` for signed inbound, grounding, and simulated Messenger delivery. Set `CONVERSE_TEST_REAL_OLLAMA=1` to use installed local models while still simulating delivery. Run on the isolated test database. For real delivery, use an explicitly approved test Page/recipient and send one new inbound question.
- **No incoming message:** check public HTTPS accessibility, Meta verification and subscription, the configured Page ID, and `converse-inbound`. A 403 on POST commonly points to the app secret, stripped signature, or modified request bytes. Inspect `php artisan queue:failed` and worker logs without printing webhook bodies, credentials, or customer data.
- **Document queued or failed:** check the `converse-knowledge` worker, private volume, embedding model, and file extraction status. Use **Re-index** after correcting the cause.
- **No AI reply:** check the three AI controls, outbound toggle, token status, reply window, worker health, and agent collection scope. Open a thread’s AI decision and source IDs to distinguish an answer from a handoff. **Accepted by provider** confirms Meta accepted the request. An **Unknown** delivery requires reconciliation before retrying to avoid duplicate messages.

## Roll back

For a thread-specific stop, switch to **Human** or **Paused**. To stop automatic replies more broadly, disable the channel or set `CONVERSE_AI_AUTO_REPLY=false`, then refresh config and restart workers. To stop all sends, including manual replies, set `CONVERSE_OUTBOUND_ENABLED=false`. Retain the inbound worker if staff still need incoming messages. Verify worker state and pending jobs before declaring sends stopped. Roll application code and assets back together. Back up MySQL and private storage before schema changes, and preserve `APP_KEY`, queued work, and uploaded documents when reverting a release.
