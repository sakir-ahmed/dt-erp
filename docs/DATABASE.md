# Database

ERP uses MySQL for deployed installations and supports SQLite for local development and tests. Each installation stores one company's data. The current schema has no tenant or branch columns.

## Implemented tables

| Tables | Purpose |
| --- | --- |
| `products` | SKU, name, unit, fixed-point BDT sale price, and active state. SKU is unique within regular or demo records. |
| `users` | User identity, unique email, hashed password, and local demo account marker |
| `sessions`, `password_reset_tokens` | Laravel session storage and scaffold password-reset storage |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | Company-wide Spatie authorization |
| `cache`, `cache_locks` | Laravel database cache storage and locks |
| `jobs`, `job_batches`, `failed_jobs` | Laravel database queue bookkeeping |

Run `php artisan migrate --seed` from `web-app/` to apply the schema and seed the built-in roles. Business tables will be documented with their workflows.
