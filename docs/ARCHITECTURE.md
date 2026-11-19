# Architecture and coding standards

DT ERP serves one company per installation. Roles and permissions govern access within that company. Keep tenant IDs, branch IDs, branch selection, and branch-isolation logic out of the application and schema. Define ERP roles and their permissions alongside the workflows they govern.

## Stack

- Laravel 13 and PHP 8.3+.
- MySQL in production. SQLite can be used for local development and tests that do not depend on MySQL behavior. Verify database-specific behavior on MySQL.
- Inertia 3, Vue 3 with `<script setup>`, and Tailwind CSS 4. Follow the [UI standards](DESIGN.md).
- Pest for feature and unit tests, Laravel Pint for formatting, and Larastan for static analysis.

## Application code

- Use Form Requests for input validation. Keep controllers thin and put business operations in Actions that web and API controllers can share.
- Enforce permissions server-side and scope records according to the operation's access rules. Cover allowed and denied cases in feature tests.
- Use database transactions for operations that must commit together. Use fixed-point decimals for monetary values and decimal strings in API payloads.
- Store timestamps in UTC and display them in Asia/Dhaka using shared formatters.
- Keep docs and tests current as schemas, business rules, and API contracts change.

DT Restora is the reference for UI and engineering conventions. DT MarketGrid is a secondary reference for adapting the shared admin kit to a different product.

## Implementation status

The web app has an Inertia dashboard shell, English and Bangla localization, theme controls, Pest, and Larastan. The dashboard requires a signed-in user. From `web-app/`, run `php artisan migrate --seed` to initialize the schema and built-in roles. Public registration is disabled. Create the first administrator interactively with `php artisan erp:user:create --administrator`. The same command without the flag creates a staff account.

| Role | Workspace access |
| --- | --- |
| Administrator | Dashboard, staff account management, and product catalog management |
| Staff | Dashboard and read-only product catalog |

Existing accounts keep dashboard access. Access to the user directory requires `users.view`. Creating or editing staff accounts requires `users.manage`. Administrator accounts are provisioned in the terminal. Demo administrators have read-only access to demo accounts and can manage demo catalog records. Regular accounts use a separate catalog. Additional permissions will be defined with their workflows.

## Local demo sign-in

Set `ERP_DEMO_LOGIN=true` in a local development environment, then run `php artisan migrate` and `php artisan db:seed --class=DemoUserSeeder`. Sign-in shows administrator and staff demo buttons when the accounts have been seeded. The endpoint only accepts dedicated demo accounts and is unavailable outside the local environment. Keep the demo setting disabled on shared installations.
