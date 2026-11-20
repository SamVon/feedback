# Easy Gold Feedback Service & Staff Web

A Laravel service providing an internal Feedback API and Filament staff panel, separate from the existing Easy Gold backend. The data on this machine is **DEMO data only**. The service is not connected to the live system and has not been deployed.

For the customer-facing API used directly by the mobile app, see the [App Feedback API guide (Thai)](docs/APP_FEEDBACK_API_TH.md).

## Getting started

The runtime last verified on November 17, 2026 was **PHP 8.5.10, Laravel 13.42.0, Filament 5.11.2, and Livewire 4.4.8** (an earlier setup was verified with PHP 8.4.25). Resolved dependency versions are recorded in `composer.lock`. The project uses **MySQL for both the web application and tests**. On this machine, MySQL 9.7.2 from DBngin runs at `127.0.0.1:3303` as configured in `.env`. The setup uses InnoDB, UTF-8 (`utf8mb4_0900_bin`), UTC, and the `READ COMMITTED` isolation level.

```bash
cd /Users/sammy/Documents/ChatGPT/Feedback/feedback-service
# The existing .tool-versions selects PHP 8.4.25; check php -v and composer check-platform-reqs against the runtime in use
composer install
cp .env.example .env
php artisan key:generate
# Create the easy_gold_feedback and easy_gold_feedback_test MySQL schemas before migrating
php artisan migrate
php artisan filament:assets
```

For an existing workspace, do not copy `.env` or generate a new key again: the demo data contains values encrypted with the existing key.

Configure `.env` for local development:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8091
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3303
DB_DATABASE=easy_gold_feedback
DB_USERNAME=root
DB_PASSWORD=
DB_COLLATION=utf8mb4_0900_bin
MYSQL_CLIENT_BIN=/Users/Shared/DBngin/mysql/9.7.2_arm64/bin
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=180
SESSION_DRIVER=database
FEEDBACK_STAFF_AUTH=local
FEEDBACK_UI_LOCALE=en
FEEDBACK_APP_TOKEN_SHA256=
FEEDBACK_COHORT_TOKEN_SHA256=
```

`en` is the default for preview only and can be changed to `lo`; it is not a business-approved default language. Both languages can be selected from the topbar. Domain translations are available in Lao and English; some standard Filament and validation messages still fall back to English. Have a Lao speaker review the wording before production use.

```bash
# Seed 1,500 demo responses across 260 accounts; the seeder creates no admin account that can log in
php artisan db:seed --class=DemoSeeder

# Create a local account with a hidden password prompt (at least 14 characters)
# Grant only the permissions needed; this list is for a developer testing every page
php artisan feedback:create-local-staff your-name@example.test --name="Local developer" --permissions="analytics.read,feedback.read,customers.read,customer_identity.read,report.export,survey.read,survey.edit,rules.read,rules.edit,changes.read,changes.approve,changes.publish,audit.read"

php artisan serve --host=127.0.0.1 --port=8091
# Open another terminal for export jobs
php -d memory_limit=512M artisan queue:work --tries=3 --timeout=120
# Run the scheduler to recover stuck jobs and remove expired export files
php artisan schedule:work
```

Open the [staff panel](http://127.0.0.1:8091/admin). There is no default password or public registration. Accounts created by the DEMO seeder and accounts used for browser verification are disabled. The staff creation command works only locally; `FEEDBACK_STAFF_AUTH=disabled` and production disable panel access until the real integration is ready.

To provision a local account through migration `2026_11_16_084933_seed_local_feedback_admin`, set `FEEDBACK_LOCAL_ADMIN_EMAIL` and `FEEDBACK_LOCAL_ADMIN_PASSWORD` only while running `php artisan migrate` or `php artisan db:seed --class=LocalAdminSeeder` directly, then clear the credentials. The account receives all Admin permissions and its password is hashed. The seeder does not modify existing accounts or run in production. There is no default password in the source code.

The panel uses assets published by Filament and CSS in `public/css/easy-gold.css`, so Node/Vite is not required to view the pages implemented here. Laravel skeleton Vite files remain available for future frontend work.

MySQL clients (`mysql`, `mysqldump`) must be on PATH or their directory specified in `MYSQL_CLIENT_BIN` for backup/restore tests. The path above is specific to this machine; adjust it for other machines. The root account above is for local DBngin only; deployments should use a service account restricted to the Feedback database.

Create the databases before starting on a new machine:

```sql
CREATE DATABASE easy_gold_feedback CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
CREATE DATABASE easy_gold_feedback_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
```

The test account must be able to manage the `easy_gold_feedback_test` schema and create/drop temporary `easy_gold_feedback_test_*` schemas. A test guard rejects the web application's database before `RefreshDatabase` runs and enforces the test database name in `phpunit.xml`.

The previous local data was migrated to MySQL: 28 tables and 4,353 rows, including 1,500 responses and 2,060 answers. All rows were compared before commit. Keep the existing `APP_KEY` to read encrypted data. The old database file is retained as a backup only; there is no SQLite connection in the runtime or test suite.

## Implemented features

- Internal API: config, eligibility/invitations, prompt events, submission/read, and cohort start/batches/complete.
- Bearer service tokens are checked against SHA-256 hashes from the environment; app backend and user adapter callers and scopes are separate.
- `always_on` requires no transaction; `auto_prompt` requires an unexpired invitation for the actor, caller, and version, created from a context verified as successful by the backend.
- Pop-up quotas are separated by actor and transaction type: three successfully stored responses, with a 30-day cooldown from the latest response's `received_at`. Shown/dismissed events, retries, and `always_on` are excluded.
- **Business decision pending:** the reset policy and what happens after three responses. Until confirmed, the system blocks after three, with no reset every 30 days or when a rule/survey version changes.
- Rules/Approvals pages display separate rules for BUY_GOLD, SALE_GOLD, and other transaction types; each type can be enabled or disabled through draft/approval.
- Responses, answers, and idempotency records commit in one transaction. Counts and cooldowns are derived from the committed response ledger under an actor lock, avoiding a separate counter that could drift. The unique scope is `(caller, operation, key)`, with business keys for submissions, invitations, events, and batches.
- Validates questions, versions, answer types, options, integer ratings 1–5, and text length, as well as per-actor rate limits.
- Navigation: Dashboard, Feedback center, Feedback, Customers, Rating questions, Other questions, Surveys, Rules, Customer Service, Approvals, Reports & exports, and Audit log.
- Rating questions (`/admin/rating-questions`) have full-page create/edit screens (`/create`, `/{version}/edit`). First set the question; a table then always contains levels 5, 4, 3, 2, and 1. Editing a level opens a full-page answer-set step; select Apply answer set to return to the main page, then Save draft to save everything. Reasons can be configured separately for each rating, with options added, removed, and reordered. The allowed number of selections is set by version config (technical limit: 1–50 options), and reasons can be required or optional. An Admin Preview is available, and the API validates reasons against the rating, question, and version. See the contract in [API.md](docs/API.md).
- Other questions (`/admin/other-questions`) displays one question per row, with status, enable/disable, edit, add/remove, and reorderable options. Changes go through draft/approval/publish while preserving rating questions and old responses.
- Pop-up (`source=auto_prompt`) has a rating, rating-specific reasons, and an optional additional-comment field. The Feedback page (`source=always_on`) has a rating and enabled choice/text questions. The API validates answers against the same channel config used by Admin Preview, which can switch between both views.
- Customer Service (`/admin/customer-service`) configures the Feedback entry only: status, an in-system icon, and English/Lao name and description through the existing approval/publish workflow. Other services and mobile source code are not changed.
- Rules separate the Pop-up schedule from cooldown and display the Always-On limit of three times per day as a proposal that is not yet enforced.
- Surveys configure channels and localized title/intro/submit/cancel/thank-you text. The API adds `app_config` for the app backend to forward; see [details and limitations](docs/API.md).
- CSV/Excel/PDF exports run through the existing queue using PhpSpreadsheet 5.11.0 and mPDF 8.3.1, added with user authorization. They require PHP gd/mbstring/zip/xml as reported by `composer check-platform-reqs`. A worker memory limit of 512M is recommended; the full test suite also uses 512M. Exports and dashboards share filters and definitions.
- Survey/rule workflow: draft → submitted → approved → published, with revision checks. Previously published versions cannot be edited through the service or UI. Rejected drafts can be edited after the revision increases.
- CSAT, average, rating distribution, daily trend, choice analytics, latest responses, and per-account summaries share a filter set.
- Customer lists come from snapshots; answered/unanswered/incomplete is separate from not_invited/eligible/shown/unknown. Captured timestamps and a warning appear when the newest snapshot is incomplete.
- CSV export fixes response membership when the job is created, uses a database queue and private local storage, expires after 24 hours, and checks permissions at request, worker, and download time as well as file ownership. Spreadsheet formulas are escaped.
- Names and phone numbers are encrypted at the model level and visible only with `customer_identity.read`; CSV exports exclude names, phone numbers, KYC, and banking data.
- Audit events cover draft, submit, approve/reject, publish, snapshot completion, and export request/ready/download.

## Data and metrics

Documentation matching the code: [Thai integration guide](docs/INTEGRATION_TH.md), [OpenAPI 3.1 JSON](docs/openapi.json), [API rules and details](docs/API.md), and [Bruno requests](../bruno/EasyGold-Feedback/README.md).

The UI uses **Asia/Vientiane** dates; the database and API use **UTC**. Reports filter `received_at` from 00:00 on the first selected day through 00:00 on the day after the last selected day, exclusive.

A survey version and question must be selected to calculate rating/choice metrics. Ratings from different questions are not combined. When there are no ratings, display `null`/No data. Average rating and CSAT use answers to the selected rating question as the denominator; Responses and Unique accounts count all responses/actors matching the filters, without requiring an answer to that optional question.

Choice percentage = responses selecting an option / responses answering that choice question. Multiple-choice percentages may total more than 100%. Daily trend charts show only dates with ratings and do not fill missing dates with zero scores.

DEMO data has 1,000 five-star, 275 four-star, 210 two-star, and 15 one-star ratings: a total score of 6,535 / 1,500 = **4.36**, with CSAT = 1,275 / 1,500 = **85%**. Select a date range covering all seeded data, approximately the previous 30 days from the seed date; if data is seeded before midnight and opened the next day, the default 30-day range may not include the first day.

Per-customer counts and CSAT use the same filters. History displays the latest 20 responses and every question in each response. Invitation status is **all time for the selected survey/version**, separate from the response date range as labeled in the table. It is not used as the denominator for response rate.

## Permission scope

| Permission | Allowed access |
|---|---|
| `analytics.read` | Dashboard / rating summaries |
| `feedback.read` | Feedback and answers in customer history |
| `customers.read` | Cohort account lists and response status |
| `customer_identity.read` | Names and phone numbers, in addition to customer read access |
| `report.export` + `feedback.read` | Create/download the user's own exports |
| `survey.read`, `survey.edit` | Read / create and edit survey drafts |
| `rules.read`, `rules.edit` | Read / create and edit rule drafts |
| `changes.read` | View approval requests and proposed previews |
| `changes.approve`, `changes.publish` | Approve/reject / publish |
| `audit.read` | Audit log |

Gates and application services also enforce permissions server-side. Hiding an action in the UI is not the only protection. Feedback/Customers have model policies, and resource authorization prevents editing received answers through the staff UI.

The system does not yet require the editor and approver to be different people because that business policy is pending. Roles from the existing system are not automatically interpreted as permissions.

## Existing backend integration

Target path: `App → existing backend → Internal API → Feedback DB`; Filament calls application services internally.

The existing code was reviewed: auth claims include `_id`, `role`, and `oic`; `oic` may be empty. User-service restricts admin customer listing, and `TransactionHistory.UserId` is the wallet owner, so it is not treated as the actor in every case. This project did not modify any of the three existing services.

- `StaffIdentityProvider` / `VerifiedStaffIdentity` provide an interface; the bound implementation rejects access because a contract is not yet available. It does not validate real JWTs or create staff sessions from kpv-auth-service.
- `CustomerSnapshotSource` defines an interface for an adapter that reads a consistent manifest/batches; there is no HTTP adapter using real credentials yet.
- The internal API receives verified context from the app backend and does not itself validate transactions against balance-service. Success/revert/actor workflows must be confirmed before granting service credentials to a real adapter.
- This version supports `identity_type=account` only; One ID is stored as nullable but is not used to merge people.
- The mobile app pop-up and app-facing routes still need to be implemented by the owning backend/mobile teams. This service can test eligibility and accept responses, but that does not prove the mobile pop-up works.

## Testing

```bash
php artisan test --compact
vendor/bin/pint --test
php artisan view:cache
composer validate --strict
composer check-platform-reqs
```

The test suite uses the MySQL schema `easy_gold_feedback_test` and random `easy_gold_feedback_test_*` schemas for multiple processes without clearing DEMO data in `easy_gold_feedback`. Coverage includes commit/replay/conflict, lost receipts, six-process concurrency, rating/choice/text/version, auth/scope/ownership, rate limits, expired invitations, disabled channels, duplicate/out-of-order events, incomplete/deduplicated cohorts, CSAT over 1,500 responses, timezone boundaries, revision/published immutability, Filament actions, authorization, and exports.

Verification results and remaining limitations are in [docs/VERIFICATION.md](docs/VERIFICATION.md), including desktop/tablet screenshots from a real browser using DEMO data only.

## Operations and pre-production limitations

- Production requires HTTPS/private routing at the gateway, service credentials issued and rotated by the systems team, a staff SSO contract, session expiry/revocation, and confirmed permission mapping. Do not use local staff mode in production.
- MySQL contention/actor row locks were tested locally using `READ COMMITTED` so reads see the latest committed data after waiting for a lock, and InnoDB to roll back response/answers/idempotency together. Production load, failover, and MySQL topology still require testing before deployment.
- Idempotency records **do not yet expire automatically**, so an old retry cannot become a new response. Retention must be agreed before adding a purge; the unique client submission remains attached to the response.
- The initial API edge limit is 600 requests/minute/IP. Configure service-aware rate limiting at the gateway and trusted proxies for the deployment. Actual response anti-abuse rate limits use actor/source (and transaction type for auto_prompt) according to the published rule, separate from the three-response quota.
- `feedback:recover-exports` recovers queued/processing jobs stuck for more than 10 minutes; `feedback:prune-exports` removes expired files while retaining audit/job history. Failed jobs are visible in the tables and `queue:failed`; workers may create temporary files that require cleanup under the agreed retention policy.
- Audit data is stored in the same database; this version has no WORM/external tamper-evident sink, alerting, or retention pipeline.
- Survey names are tied to the existing code and cannot be changed through a draft; create a new code to rename. Previously published surveys still accept answers and preserve their meaning. There is no retirement/closing policy yet, and publishing an older version than the latest as a rollback is not allowed.
- Per-customer queries still issue queries per row; trends read ratings through a cursor. This is suitable for initial testing but requires benchmarks/daily aggregates based on actual volume.
- Load/SLA, production DB failover, real downstream timeouts, a consistent full user-service export, and business handling of reverted transactions have not yet been tested.

### Backup/restore

MySQL `mysqldump --single-transaction --no-tablespaces --set-gtid-purged=OFF` was tested against a test schema, then restored into a new schema. Response counts and replay were checked through a new Laravel process. Commands use the client specified by `MYSQL_CLIENT_BIN` and pass passwords through the environment rather than command arguments.

Example local backup (enter the password when prompted):

```bash
mysqldump --host=127.0.0.1 --port=3303 --user=root --password \
  --single-transaction --no-tablespaces --set-gtid-purged=OFF easy_gold_feedback > feedback-backup.sql
```

Back up **APP_KEY** and private exports alongside the database. Before restoring, stop web/queue/scheduler, back up current data, then restore to a **new schema**. Check migrations/counts/known idempotency replay and identity decryption before changing `DB_DATABASE` or switching traffic. Do not create a new APP_KEY for existing data. Avoid schema-changing DDL during the dump, according to the [mysqldump documentation](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html).

Quota history reads use an actor row lock inside a transaction with `READ COMMITTED`. Actor/idempotency locks are created using a MySQL upsert that does not change the existing hash/receipt, acquiring a write lock directly and avoiding shared-lock upgrade deadlocks, as discussed in [InnoDB transaction isolation](https://dev.mysql.com/doc/refman/8.4/en/innodb-transaction-isolation-levels.html).

## Version references

Runtime requirements were checked against [Laravel 13 deployment](https://laravel.com/framework/docs/13.x/deployment) and [Filament 5 installation](https://filamentphp.com/docs/5.x/introduction/installation). The application uses APIs from the vendor versions actually installed, rather than older Filament examples.
