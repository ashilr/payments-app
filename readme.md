# Payments API

> A production-grade fintech payment system built with **Symfony 6.4** — featuring atomic fund transfers, double-entry bookkeeping, a priority-ordered rule engine, idempotency, pessimistic locking, compliance-oriented audit trails (entity-scoped queries + metadata masking), and a config-driven rate limiter.

**Install and run:** see [Quick start — install & run](#quick-start--install--run) (`composer install`, `php bin/console …`, Docker optional).

![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)
![Symfony](https://img.shields.io/badge/Symfony-6.4-000000?logo=symfony&logoColor=white)
![PHPUnit](https://img.shields.io/badge/PHPUnit-12-3F7BBF?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?logo=mysql&logoColor=white)
![Redis](https://img.shields.io/badge/Redis-latest-DC382D?logo=redis&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white)

> **AI-assisted development** — This project was built with **AI assistance**, with clear separation of roles:
> - **Planning:** **Claude Opus** — architecture, trade-offs, task breakdown, and review strategy.
> - **Implementation:** **Claude Sonnet 4.6** — code, refactors, tests, and documentation (primarily via **Cursor**).
>
> See **[AI-assisted development](#ai-assisted-development)** below and **[`docs/AI_ASSISTANCE.md`](docs/AI_ASSISTANCE.md)** for tooling detail, prompts, and ownership expectations.

---

## Table of Contents

- [Tech Stack](#tech-stack)
- [AI-assisted development](#ai-assisted-development)
- [Quick start — install & run](#quick-start--install--run)
- [Architecture Overview](#architecture-overview)
- [Project Structure](#project-structure)
- [Database Schema](#database-schema)
- [Features](#features)
- [API Reference](#api-reference)
- [Setup & Running](#setup--running)
- [Running the Test Suite](#running-the-test-suite)
- [Contributing](#contributing)
- [Seeded Test Data](#seeded-test-data)
- [Transfer Flow (Step by Step)](#transfer-flow-step-by-step)
- [Rule Engine](#rule-engine)
- [Error Handling](#error-handling)
- [Rate Limiting](#rate-limiting)
- [High Load & Scalability Considerations](#high-load--scalability-considerations)
- [What Is Missing / Future Scope](#what-is-missing--future-scope)

---

## Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.3 |
| Framework | Symfony 6.4 |
| ORM | Doctrine ORM 3.x |
| Database | MySQL 8.0 |
| Cache / Sessions / Rate Limiting | Redis (`symfony/rate-limiter` + `symfony/lock`) |
| Serialization | `symfony/serializer` + `symfony/property-access` |
| Web Server | Nginx + PHP-FPM |
| Containerisation | Docker / Docker Compose |
| Testing | PHPUnit 12 · `symfony/test-pack` · `symfony/browser-kit` |

---

## Quick start — install & run

### Option A — Docker (recommended)

```bash
git clone <repo-url> && cd payments-app
cp .env.example .env                                    # edit DATABASE_URL / secrets if needed
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console doctrine:fixtures:load --no-interaction   # optional demo data
```

Open **http://localhost:8080** (Nginx → PHP-FPM). MySQL and Redis run as Compose services; `DATABASE_URL` in `.env` should match the `db` / `redis` service hostnames from `docker-compose.yml`.

### Option B — Local PHP + MySQL + Redis

Requires PHP **8.3+**, Composer, MySQL **8**, Redis, and extensions (`pdo_mysql`, `mbstring`, `bcmath`, etc.).

```bash
git clone <repo-url> && cd payments-app
composer install
cp .env.example .env
# Set DATABASE_URL (MySQL) and REDIS_URL in .env for your machine
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:fixtures:load --no-interaction   # optional
```

Run the app with your web server pointing at `public/` (e.g. `symfony server:start -d` from the Symfony CLI, or Nginx/Apache vhost). Ensure `APP_ENV=dev` (or `prod` with proper secrets) and clear cache after env changes: `php bin/console cache:clear`.

### Verify

```bash
php bin/console doctrine:schema:validate
curl -s http://localhost:8080/api/v1/transfer/audit-logs | head -c 200
```

Full reset, test commands, and troubleshooting: [Setup & Running](#setup--running) and [Running the Test Suite](#running-the-test-suite).

---

## Architecture Overview

### 1. Full Request Pipeline

```
                          ┌────────────────────────────────────┐
                          │         HTTP REQUEST               │
                          └───────────────────┬────────────────┘
                                              │
                          ┌───────────────────▼────────────────┐
                          │   RouterListener                   │  kernel.request [p=256]
                          │   Resolves route → controller      │
                          └───────────────────┬────────────────┘
                                              │
                          ┌───────────────────▼────────────────┐
                          │   RateLimitSubscriber                │  kernel.request [p=20]
                          │   Sliding-window · Redis             │
                          │   Key: client IP                     │
                          └───────────────────┬────────────────┘
                                              │
                            ┌─────────────────┴─────────────────┐
                            │                                   │
                     tokens available                    limit exceeded
                            │                                   │
                            │                   ┌───────────────▼───────────────┐
                            │                   │  HTTP 429 JSON                │
                            │                   │  Retry-After · X-RateLimit-*  │◄─ short-circuit
                            │                   └───────────────────────────────┘
                            │
              ┌─────────────▼──────────────────────────────────────────────────┐
              │                    Route dispatch                               │
              └─────────────────────────┬──────────────────┬───────────────────┘
                                        │                  │
                 POST /api/v1/transfer  │                  │  POST /api/v1/reversal/{id}
                                        │                  │
              ┌─────────────────────────▼──────┐  ┌────────▼────────────────────────┐
              │  TransferController           │  │  ReversalController             │
              │  · JSON body                  │  │  · JSON body + reason           │
              │  · Idempotency-Key header     │  │  · Idempotency-Key header       │
              └─────────────────────────┬──────┘  └────────┬────────────────────────┘
                                        │                  │
              ┌─────────────────────────▼──────┐           │
              │  TransferRequestValidator      │           │
              │  → TransferRequest DTO         │           │
              └─────────────────────────┬──────┘           │
                                        │                  │
              ┌─────────────────────────▼──────┐  ┌─────────▼─────────────────────────┐
              │  TransferService             │  │  ReversalService                │
              │  (14-step pipeline)          │  │  (11-step pipeline)               │
              └─────────────────────────┬──────┘  └─────────┬─────────────────────────┘
                                        │                   │
                                        └─────────┬─────────┘
                                                  │
                                    ┌─────────────┴─────────────┐
                                    │                           │
                                SUCCESS                    EXCEPTION
                                    │                           │
              ┌─────────────────────▼──────┐    ┌───────────────▼───────────────┐
              │  HTTP 201 / 200 + JSON     │    │  DB rollback                   │
              └─────────────────────┬──────┘    │  Audit rows via DBAL (failures)│
                                    │           └───────────────┬────────────────┘
                                    │                           │
                                    │           ┌───────────────▼────────────────┐
                                    │           │  kernel.exception             │
                                    │           │  ApiExceptionSubscriber       │
                                    │           │  HTTP 4xx / 500 JSON          │
                                    │           └───────────────┬────────────────┘
                                    │                           │
              ┌─────────────────────▼───────────────────────────▼────────────────┐
              │  RateLimitSubscriber (kernel.response, p=-10)                     │
              │  X-RateLimit-Limit · Remaining · Reset                             │
              └────────────────────────────────────────────────────────────────────┘
```

---

### 2. TransferService — 14-Step Pipeline

```
TransferService::transfer()
│
├─ 1.  BEGIN database transaction
│
├─ 2.  Idempotency check
│       └─ findByIdempotencyKey(key) → existing?
│             YES → ROLLBACK + return cached Transaction
│
├─ 3.  Lock accounts (SELECT FOR UPDATE — PESSIMISTIC_WRITE)
│       ├─ AccountRepository::findForUpdate(from_account_id)
│       └─ AccountRepository::findForUpdate(to_account_id)
│              null → throw RuntimeException → 422
│
├─ 4.  TransferRuleEngine::apply(from, to, amount)
│       │  Rules run in descending priority order
│       ├─ [p=50] DifferentAccountsRule   from ≠ to
│       │         └─ fail → InvalidArgumentException     → 400
│       ├─ [p=40] AccountNotBlockedRule   neither blocked
│       │         └─ fail → AccountBlockedException      → 422
│       │                   + ACCOUNT_BLOCKED audit entry
│       ├─ [p=30] SufficientBalanceRule   balance ≥ amount
│       │         └─ fail → DomainException              → 422
│       ├─ [p=20] FraudDetectionRule      heuristic check
│       │         └─ fail → FraudAlertException          → 422
│       │                   + FRAUD_ALERT audit entry
│       └─ [p=10] TransferLimitRule       per-type limit
│                 └─ fail → DomainException              → 422
│
├─ 5.  new Transaction(from, to, amount, PENDING)
│       └─ TransactionRepository::save()
│
├─ 6.  Debit sender    newFromBalance = bcsub(balance, amount, 2)
├─ 7.  Credit receiver newToBalance   = bcadd(balance, amount, 2)
│
├─ 8.  LedgerEntry::DEBIT  (fromAccount, newFromBalance)
├─ 9.  LedgerEntry::CREDIT (toAccount,   newToBalance)
│       └─ LedgerEntryRepository::save() ×2
│
├─ 10. Transaction::markCompleted()  → status = SUCCESS
│
├─ 11. AuditLogService::log('TRANSFER_SUCCESS')
│       └─ raw DBAL INSERT (inside TX — rolled back on flush failure)
│
├─ 12. EntityManager::flush()   write all ORM changes to DB
├─ 13. Connection::commit()     finalise the DB transaction
│
└─ 14. return Transaction (SUCCESS)
```

---

### 3. ReversalService — 11-Step Pipeline

```
ReversalService::reverse(transactionId, reason)
│
├─ 1.  BEGIN database transaction
│
├─ 2.  Idempotency check → return cached reversal if key exists
│
├─ 3.  Fetch + validate original Transaction
│       ├─ not found         → InvalidArgumentException  → 400
│       ├─ status ≠ SUCCESS  → DomainException           → 422
│       ├─ isReversal = true → DomainException           → 422
│       └─ already reversed  → DomainException           → 422
│
├─ 4.  Lock both accounts (SELECT FOR UPDATE)
│       Reversal direction: original-TO debited, original-FROM credited
│
├─ 5.  Check neither account is blocked
│       └─ blocked → AccountBlockedException             → 422
│
├─ 6.  new Transaction(debit, credit, amount, isReversal=true, ref=original)
│
├─ 7.  Debit original receiver   bcsub(balance, amount, 2)
├─ 8.  Credit original sender    bcadd(balance, amount, 2)
│
├─ 9.  LedgerEntry::DEBIT + LedgerEntry::CREDIT
│
├─ 10. Transaction::markCompleted() + AuditLogService::log('REVERSAL_SUCCESS')
│
└─ 11. flush() + commit() → return reversal Transaction
```

---

### 4. Failure Path & Audit Guarantee

```
Any exception thrown inside TransferService or ReversalService
│
├─ Connection::rollBack()          ← all ORM changes discarded
│
├─ AuditLogService::log(...)       ← raw DBAL INSERT on same connection
│   ACCOUNT_BLOCKED / FRAUD_ALERT  in auto-commit mode → always persists
│   TRANSFER_FAILED / REVERSAL_FAILED                    even after rollback
│
├─ LoggerInterface::error(...)     ← structured PSR-3 log entry
│
└─ re-throw exception
        │
        ▼
   kernel.exception
   ApiExceptionSubscriber::onKernelException()
        │
        ├─ ValidationException       → 400  { "success": false, "message": "Validation failed.", "errors": { ... } }
        ├─ InvalidArgumentException  → 400  { "success": false, "message": "msg" }
        ├─ HttpExceptionInterface    → own  { "success": false, "message": "msg" }
        ├─ DomainException           → 422  { "success": false, "message": "msg" }
        ├─ RuntimeException          → 422  { "success": false, "message": "msg" }
        └─ Throwable (unexpected)    → 500  { "success": false, "message": "..." }
                                              (+ exception, file, trace in APP_ENV=dev)
```

---

### 5. Data Stores

```
┌───────────────────────────────────────────┐ ┌───────────────────────────────────────────┐
│                 MySQL 8.0                 │ │                  Redis                    │
├───────────────────────────────────────────┤ ├───────────────────────────────────────────┤
│ Core tables                               │ │ payment.cache pool                        │
│   users ─1:N─> accounts                   │ │   · rate limiter (sliding window, per IP) │
│   accounts ─1:N─> transactions            │ │   · application cache (optional)          │
│   transactions ─1:2─> ledger_entries      │ │                                           │
│   audit_logs (events, entity_type/id)     │ │ Sessions: filesystem mock in APP_ENV=test │
└───────────────────────────────────────────┘ └───────────────────────────────────────────┘
```

---

## Project Structure

```
src/
├── Controller/
│   └── Api/
│       ├── TransferController.php        POST /api/v1/transfer
│       │                                 GET  /api/v1/transfer/audit-logs
│       ├── AuditController.php           GET  /api/v1/audit/{entityType}/{entityId}
│       └── ReversalController.php        POST /api/v1/reversal/{transactionId}
├── DTO/
│   ├── TransferRequest.php               Validated request payload
│   └── Response/
│       ├── ApiResponse.php               Unified success/error envelope
│       ├── TransferResponse.php          type: "transfer"
│       ├── ReversalResponse.php          type: "reversal"
│       ├── AuditLogItemResponse.php      Single audit-log entry
│       └── AuditLogListResponse.php      type: "audit-log-list"
├── Entity/
│   ├── User.php                          email, roles, UserStatus (ACTIVE \| BLOCKED)
│   ├── Account.php                       accountNumber, accountType, balance, isBlocked
│   ├── Transaction.php                   idempotencyKey, isReversal, referenceTransaction
│   ├── LedgerEntry.php                   Double-entry · balanceAfter
│   └── AuditLog.php                      Immutable · entity keys + masked JSON context
├── Enum/
│   ├── UserStatus.php                  ACTIVE | BLOCKED
│   ├── AccountType.php                 SAVINGS | CURRENT | SALARY | FIXED
│   ├── TransactionMode.php               TRANSFER
│   ├── TransactionStatus.php           PENDING | SUCCESS | FAILED
│   └── LedgerEntryType.php             DEBIT | CREDIT
├── Exception/
│   ├── ValidationException.php         400 — carries field → message map
│   ├── AccountBlockedException.php     422 — carries accountId for audit
│   └── FraudAlertException.php           422 — carries from/to/amount for audit
├── EventSubscriber/
│   ├── ApiExceptionSubscriber.php      Converts exceptions to typed JSON responses
│   └── RateLimitSubscriber.php         Sliding-window rate limiter (429 on breach)
├── Repository/
│   ├── AccountRepository.php           findForUpdate() — SELECT FOR UPDATE by account UUID
│   ├── TransactionRepository.php       findByIdempotencyKey(), findReversalForTransaction()
│   ├── LedgerEntryRepository.php       save() — append-only
│   └── AuditLogRepository.php          findRecent(), findByEntity()
├── RuleEngine/
│   ├── TransferRuleEngine.php          Tagged-iterator orchestrator
│   └── TransferRules/
│       ├── TransferRuleInterface.php
│       ├── DifferentAccountsRule.php   priority 50  → 400 on same account
│       ├── AccountNotBlockedRule.php   priority 40  → 422 + ACCOUNT_BLOCKED audit
│       ├── SufficientBalanceRule.php   priority 30  → 422 on insufficient funds
│       ├── FraudDetectionRule.php      priority 20  → 422 + FRAUD_ALERT audit
│       └── TransferLimitRule.php       priority 10  → 422 on per-type cap exceeded
├── Service/
│   ├── TransferService.php             14-step atomic transfer pipeline
│   ├── ReversalService.php             11-step atomic reversal pipeline
│   └── AuditLogService.php             Raw DBAL writes — survives DB rollback
└── Validator/
    └── TransferRequestValidator.php    Pure PHP input validation, no framework deps

Util/
└── MaskingUtil.php                     Masks sensitive strings in audit JSON metadata

tests/
├── Api/
│   └── TransferControllerTest.php      Full-stack integration tests (HTTP → DB)
└── Unit/
    ├── Validator/
    │   └── TransferRequestValidatorTest.php  UUID + amount validation
    └── RuleEngine/
        ├── DifferentAccountsRuleTest.php      Same-account guard
        ├── AccountNotBlockedRuleTest.php       Account + user blocked paths
        ├── SufficientBalanceRuleTest.php       bcmath edge cases
        ├── TransferLimitRuleTest.php           All account types at/around limit
        └── FraudDetectionRuleTest.php          Amount + currency

config/packages/
├── rate_limiter.yaml                   Sliding-window limiter (env-driven)
├── cache.yaml                          Redis pool + array adapter in test env
└── doctrine.yaml                       Test DB: dbname_suffix `_test`
```

---

## Database Schema

Schema is defined in Doctrine entities under `src/Entity/` and materialised by migrations (e.g. `migrations/Version20260325000001.php`). Charset/collation: **utf8mb4** / **utf8mb4_unicode_ci**. Primary keys for `users`, `accounts`, `ledger_entries`, and `audit_logs` use **UUID v7** strings (`CHAR(36)`), matching `transactions.id`. Enumerated fields are stored as **VARCHAR** in MySQL; PHP maps them to backed enums.

**Relationships (overview)**

```
  ┌────────────────┐        ┌────────────────┐        ┌────────────────┐        ┌────────────────┐
  │     users      │  1:N   │    accounts    │  1:N   │  transactions  │  1:2   │ ledger_entries │
  └───────┬────────┘        └───────┬────────┘        └───────┬────────┘        └────────────────┘
          │                       │                         │
          └───────────────────────┴─────────────────────────┴── audit_logs (denormalised IDs; no FKs — see below)
```

`audit_logs` stores optional `transaction_id`, `from_account_id`, `to_account_id`, and `user_id` as plain columns **without foreign keys**, so `AuditLogService` can insert rows via DBAL even after the surrounding ORM transaction rolls back.

**Tables (summary)**

| Table | Primary key | Purpose |
|-------|-------------|---------|
| `users` | `id` (CHAR(36) UUID v7) | Symfony-authenticated users; `email` unique; `status` ACTIVE or BLOCKED |
| `accounts` | `id` (CHAR(36) UUID v7) | Per-user accounts; `account_number` unique; optional `ifsc_code`; balance + type |
| `transactions` | `id` (CHAR(36) UUID v7) | Transfers/reversals; idempotency key; optional self-reference for reversals |
| `ledger_entries` | `id` (CHAR(36) UUID v7) | Double-entry lines (DEBIT/CREDIT) with `balance_after` snapshot |
| `audit_logs` | `id` (CHAR(36) UUID v7) | Immutable event trail; JSON `context` with masked sensitive fields |

### `users`

| Column | SQL type | Nullable | Constraints / notes |
|--------|----------|----------|------------------------|
| `id` | CHAR(36) | NO | PK; UUID v7 (RFC 4122), generated in the constructor |
| `email` | VARCHAR(180) | NO | Unique (`UNIQ_users_email`); Symfony user identifier |
| `name` | VARCHAR(255) | NO | Display name |
| `password` | VARCHAR(255) | NO | Hashed (`UserPasswordHasherInterface`); never plain text |
| `roles` | JSON | NO | e.g. `["ROLE_ADMIN"]`; `ROLE_USER` is always added in code |
| `status` | VARCHAR(32) | NO | `ACTIVE` (default) or `BLOCKED`; blocked users cannot move funds |
| `created_at` | DATETIME | NO | Immutable (`DateTimeImmutable` in app) |

**Indexes:** unique on `email`.

### `accounts`

| Column | SQL type | Nullable | Default | Constraints / notes |
|--------|----------|----------|---------|------------------------|
| `id` | CHAR(36) | NO | | PK; UUID v7, generated in the constructor |
| `user_id` | CHAR(36) | NO | | FK → `users.id` **ON DELETE CASCADE** |
| `account_number` | VARCHAR(13) | NO | | Unique (`UNIQ_account_number`); format `ACC` + 10 hex chars |
| `account_type` | VARCHAR(255) | NO | | See **Stored enum values** (`AccountType`) |
| `balance` | NUMERIC(15,2) | NO | `0.00` | App uses bcmath strings; do not cast to float |
| `currency` | VARCHAR(3) | NO | `INR` | ISO 4217 code |
| `ifsc_code` | VARCHAR(11) | YES | | Indian IFSC (11 chars: `AAAA0XXXXXX`); optional metadata for branch routing / compliance |
| `is_blocked` | TINYINT(1) | NO | `0` | Blocks all inbound/outbound transfers when true |
| `created_at` | DATETIME | NO | | Immutable |

**Indexes:** `idx_account_user_id` (`user_id`); unique `UNIQ_account_number` (`account_number`).

### `transactions`

| Column | SQL type | Nullable | Constraints / notes |
|--------|----------|----------|------------------------|
| `id` | CHAR(36) | NO | PK; UUID v7 (RFC 4122), generated before flush |
| `from_account_id` | CHAR(36) | NO | FK → `accounts.id` **ON DELETE RESTRICT** |
| `to_account_id` | CHAR(36) | NO | FK → `accounts.id` **ON DELETE RESTRICT** |
| `amount` | NUMERIC(15,2) | NO | Transfer amount; bcmath in app |
| `status` | VARCHAR(255) | NO | See **Stored enum values** (`TransactionStatus`) |
| `failure_reason` | VARCHAR(512) | YES | Set when status is FAILED (rarely persisted; rollbacks discard row) |
| `mode` | VARCHAR(255) | NO | See **Stored enum values** (`TransactionMode`) |
| `idempotency_key` | VARCHAR(255) | YES | Unique when not NULL (`UNIQ_transaction_idempotency_key`) |
| `reference_transaction_id` | CHAR(36) | YES | FK → `transactions.id` **ON DELETE RESTRICT**; original tx for reversals |
| `is_reversal` | TINYINT(1) | NO | Default `0`; true for reversal rows |
| `reversal_reason` | VARCHAR(512) | YES | Operator reason when `is_reversal` is true |
| `created_at` | DATETIME | NO | Immutable |

**Indexes:** `idx_transaction_from_account`, `idx_transaction_to_account`, `idx_transaction_status`; index on `reference_transaction_id` (Doctrine name `IDX_EAA81A4C76500E2D`); unique nullable `idempotency_key`.

### `ledger_entries`

| Column | SQL type | Nullable | Constraints / notes |
|--------|----------|----------|------------------------|
| `id` | CHAR(36) | NO | PK; UUID v7, generated in the constructor |
| `account_id` | CHAR(36) | NO | FK → `accounts.id` **ON DELETE RESTRICT** |
| `transaction_id` | CHAR(36) | NO | FK → `transactions.id` **ON DELETE RESTRICT** |
| `type` | VARCHAR(255) | NO | See **Stored enum values** (`LedgerEntryType`) |
| `amount` | NUMERIC(15,2) | NO | Absolute amount; direction from `type` |
| `balance_after` | NUMERIC(15,2) | NO | Account balance immediately after this line |
| `created_at` | DATETIME | NO | Immutable |

**Indexes:** `idx_ledger_account_id` (`account_id`); `idx_ledger_transaction_id` (`transaction_id`); composite `idx_ledger_account_timeline` (`account_id`, `created_at`) for chronological statements.

A successful transfer inserts **exactly two** rows (one DEBIT, one CREDIT) for the same `transaction_id`.

### `audit_logs`

| Column | SQL type | Nullable | Constraints / notes |
|--------|----------|----------|------------------------|
| `id` | CHAR(36) | NO | PK; UUID v7, generated in the constructor |
| `event` | VARCHAR(100) | NO | e.g. `TRANSFER_SUCCESS`, `FRAUD_ALERT`, `REVERSAL_FAILED` |
| `entity_type` | VARCHAR(100) | YES | Domain label (e.g. `Account`); with `entity_id` powers entity-scoped API |
| `entity_id` | CHAR(36) | YES | UUID of the primary entity (e.g. `Account.id`) |
| `transaction_id` | CHAR(36) | YES | Related transaction UUID (no FK) |
| `from_account_id` | CHAR(36) | YES | Sender account UUID (no FK) |
| `to_account_id` | CHAR(36) | YES | Receiver account UUID (no FK) |
| `amount` | NUMERIC(15,2) | YES | Denormalised amount when applicable |
| `context` | JSON | YES | Arbitrary metadata; **masked** via `MaskingUtil` before insert |
| `user_id` | CHAR(36) | YES | Authenticated user UUID (no FK) |
| `ip_address` | VARCHAR(45) | YES | IPv4 or IPv6 |
| `created_at` | DATETIME | NO | Immutable |

**Indexes:** `idx_audit_event`, `idx_audit_transaction`, `idx_audit_created_at`, composite `idx_audit_entity` (`entity_type`, `entity_id`).

### Stored enum values (application ↔ database)

| Enum (PHP) | Cases stored as VARCHAR |
|------------|-------------------------|
| `AccountType` | `SAVINGS`, `CURRENT`, `SALARY`, `FIXED` |
| `TransactionStatus` | `PENDING`, `SUCCESS`, `FAILED` |
| `TransactionMode` | `TRANSFER` (only value today) |
| `LedgerEntryType` | `DEBIT`, `CREDIT` |

### Foreign keys (referential integrity)

| Constraint | Child column | Parent | On delete |
|------------|--------------|--------|-----------|
| `FK_CAC89EACA76ED395` | `accounts.user_id` | `users.id` | CASCADE |
| `FK_EAA81A4CB0CF99BD` | `transactions.from_account_id` | `accounts.id` | RESTRICT |
| `FK_EAA81A4CBC58BDC7` | `transactions.to_account_id` | `accounts.id` | RESTRICT |
| `FK_EAA81A4C76500E2D` | `transactions.reference_transaction_id` | `transactions.id` | RESTRICT |
| `FK_E3FD73F49B6B5FBA` | `ledger_entries.account_id` | `accounts.id` | RESTRICT |
| `FK_E3FD73F42FC0CB0F` | `ledger_entries.transaction_id` | `transactions.id` | RESTRICT |

`audit_logs` is intentionally **not** linked by FK so inserts remain valid after rollbacks and do not cascade-delete history.

How these indexes are used in request paths is summarised under [Database Indexing on Critical Columns](#database-indexing-on-critical-columns) later in this document.

---

## Features

### 1. Account identifiers & IFSC (metadata)
Each account has a **UUID primary key** (`accounts.id`) — this is what clients send on **`POST /api/v1/transfer`** as `from_account_id` and `to_account_id`, together with `amount`.

Separately, each account has a human-readable **account number** generated at creation: `ACC` plus 10 uppercase hex characters (e.g. `ACCAB12CD34EF`). It is unique, indexed, and useful for fixtures, support, and display; it is **not** the transfer API identifier.

**Reversals** (`POST /api/v1/reversal/{transactionId}`) reference the original movement by **transaction UUID**, not by account number.

Optional **IFSC** on `accounts.ifsc_code` (Indian Financial System Code: bank + branch) is stored for routing/compliance metadata only — it is **not** part of the transfer JSON body. Outward rails (NEFT/RTGS) would typically add beneficiary and purpose fields in a separate integration layer.

### 2. Account Types with Per-Type Transfer Limits

| Type | Per-Transaction Limit | Typical Use |
|---|---|---|
| `SAVINGS` | 50,000.00 | Personal savings |
| `CURRENT` | 500,000.00 | Business / high-volume |
| `SALARY` | 100,000.00 | Payroll disbursements |
| `FIXED` | 10,000.00 | Term deposits |

### 3. Idempotency
Pass `Idempotency-Key: <your-key>` in the request header. If a transaction with that key already exists, the original transaction is returned immediately without re-processing — making the API safe to retry on network failures without risk of double-charging.

### 4. Concurrency Safety
Both accounts are locked with `SELECT FOR UPDATE` (`PESSIMISTIC_WRITE` in Doctrine) inside a database transaction before any balance is read or modified. Two simultaneous transfers from the same account are serialised at the database level — the second request waits for the lock and reads the correctly updated balance.

### 5. Double-Entry Ledger
Every successful transfer produces exactly two `LedgerEntry` rows:
- **DEBIT** on the sender's account — records the new `balance_after`
- **CREDIT** on the receiver's account — records the new `balance_after`

This provides a complete, immutable, auditable balance history per account that can be used to reconstruct the account balance at any point in time.

### 6. Priority-Ordered Rule Engine
Business rules are fully decoupled from service logic. Each rule is an independent class, auto-registered via `#[AutoconfigureTag]`. Adding a new rule requires only creating a new class — nothing else changes.

```
Priority 50 → DifferentAccountsRule    — same account rejected (400)
Priority 40 → AccountNotBlockedRule    — compliance hold check (422 + audit)
Priority 30 → SufficientBalanceRule    — balance check with bcmath (422)
Priority 20 → FraudDetectionRule       — amount threshold + cross-currency (422 + audit)
Priority 10 → TransferLimitRule        — per-account-type cap (422)
```

### 7. Structured Audit Logging
Events are tracked in `audit_logs`, written via raw DBAL to guarantee persistence even after a rollback. Each row can be keyed by **`entity_type` + `entity_id`** (e.g. `Account` + the account’s UUID) for compliance queries via `GET /api/v1/audit/{entityType}/{entityId}` (`entityId` is a UUID string). JSON `context` is stored with **sensitive fields masked** (`MaskingUtil`) before insert.

| Event | When | Persists after rollback? |
|---|---|---|
| `TRANSFER_SUCCESS` | Transfer committed | Inside TX |
| `TRANSFER_FAILED` | Any exception during transfer | ✅ Always |
| `ACCOUNT_BLOCKED` | Blocked account detected | ✅ Always |
| `FRAUD_ALERT` | Fraud rule triggered | ✅ Always |
| `REVERSAL_SUCCESS` | Reversal committed | Inside TX |
| `REVERSAL_FAILED` | Any exception during reversal | ✅ Always |

Reserved for future compliance modules: `COMPLIANCE_CHECK`, `AML_TRIGGERED`, `KYC_FAILED` (same table + service).

### 8. Request Validation
`TransferRequestValidator` validates the raw JSON body using pure PHP regex — no `symfony/validator` dependency. It collects all field errors before throwing, so the client receives all mistakes in a single response.

| Field | Rules |
|---|---|
| `from_account_id` | Required · UUID (RFC 4122 string) |
| `to_account_id` | Required · UUID · must differ from `from_account_id` |
| `amount` | Required · decimal string · max 2 d.p. · must be `> 0` |

### 9. Consistent JSON Response Envelope
Every endpoint returns the same top-level shape regardless of success or failure — clients always know exactly where to look.

```
Success  → { "success": true,  "data": { "type": "...", "attributes": { ... } } }
Error    → { "success": false, "message": "..." }
Validation → { "success": false, "message": "Validation failed.", "errors": { ... } }
```

### 10. Config-Driven Rate Limiter
`POST /api/v1/transfer` is protected by a **sliding-window rate limiter** backed by Redis. The limit and window are driven entirely by environment variables — no code changes needed to tune per environment. Every response carries `X-RateLimit-*` headers so clients can observe quota in real time.

### 11. Comprehensive Test Coverage
Two test suites with no overlap — integration tests verify the full HTTP → DB cycle, unit tests verify individual components in isolation with zero external dependencies.

| Suite | File | Scope |
|---|---|---|
| Integration | `tests/Api/TransferControllerTest.php` | Full HTTP stack, DB assertions, transfer + global + entity-scoped audit endpoints |
| Unit | `tests/Unit/Validator/TransferRequestValidatorTest.php` | Validator rules in isolation |
| Unit | `tests/Unit/RuleEngine/DifferentAccountsRuleTest.php` | 2 | Same-account guard |
| Unit | `tests/Unit/RuleEngine/AccountNotBlockedRuleTest.php` | 4 | Block check + exception context |
| Unit | `tests/Unit/RuleEngine/SufficientBalanceRuleTest.php` | 5 | bcmath boundary conditions |
| Unit | `tests/Unit/RuleEngine/TransferLimitRuleTest.php` | 9 | All 4 account types at/around limit |
| Unit | `tests/Unit/RuleEngine/FraudDetectionRuleTest.php` | 6 | Amount threshold + cross-currency |

### 12. PHPDoc Coverage
Complete PHPDoc on every public class and method across the codebase: entities, services, repositories, rule engine, exception classes, enums, validators, event subscribers, and response DTOs — including `@param`, `@return`, and `@throws` annotations with explanations of non-obvious constraints.

---

## API Reference

### Response Envelope

Every endpoint — success or failure — returns the same top-level structure.

**Success**
```json
{
  "success": true,
  "data": {
    "type":       "<resource-type>",
    "attributes": { }
  }
}
```

**Error**
```json
{
  "success": false,
  "message": "Human-readable reason."
}
```

**Validation error**
```json
{
  "success": false,
  "message": "Validation failed.",
  "errors": {
    "fieldName": "Human-readable constraint message."
  }
}
```

> `data` and `errors` are omitted from the response entirely when null — the body only contains what is meaningful.

---

### POST `/api/v1/transfer`

Create a fund transfer between two accounts.

**Headers**
```
Content-Type: application/json
Idempotency-Key: <unique-string>   (optional)
```

**Request body**
```json
{
  "from_account_id": "550e8400-e29b-41d4-a716-446655440000",
  "to_account_id":   "6ba7b810-9dad-11d1-80b4-00c04fd430c8",
  "amount":            "250.00"
}
```

**Rate-limit headers** (present on every response to this endpoint)
```
X-RateLimit-Limit:     10
X-RateLimit-Remaining: 9
X-RateLimit-Reset:     1775461238
```

**Responses**

| Status | Trigger | `data.type` |
|---|---|---|
| `201 Created` | New transfer committed | `transfer` |
| `200 OK` | Duplicate `Idempotency-Key` — cached result returned | `transfer` |
| `400 Bad Request` | Validation failure | — |
| `422 Unprocessable` | Business rule violation (blocked, insufficient funds, fraud, limit) | — |
| `429 Too Many Requests` | Rate limit exceeded | — |
| `500 Internal Server Error` | Unexpected error | — |

**Example request**
```bash
curl -X POST http://localhost:8080/api/v1/transfer \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: order-abc-001' \
  -d '{"from_account_id":"550e8400-e29b-41d4-a716-446655440000","to_account_id":"6ba7b810-9dad-11d1-80b4-00c04fd430c8","amount":"250.00"}'
```

**201 — Transfer created**
```json
{
  "success": true,
  "data": {
    "type": "transfer",
    "attributes": {
      "transactionId":  "019612ab-c3d4-7e5f-a6b7-c8d9e0f1a2b3",
      "status":         "SUCCESS",
      "idempotencyKey": "order-abc-001"
    }
  }
}
```

**400 — Validation error**
```json
{
  "success": false,
  "message": "Validation failed.",
  "errors": {
    "from_account_id": "from_account_id must not be blank.",
    "amount":            "Amount must be greater than 0."
  }
}
```

**422 — Business rule violation**
```json
{
  "success": false,
  "message": "Insufficient balance."
}
```

**429 — Rate limit exceeded**
```json
{
  "success": false,
  "message": "Too many requests. Please slow down and try again."
}
```

---

### GET `/api/v1/transfer/audit-logs`

Query the audit log with optional filters. Returns up to 100 entries per request, ordered by most recent first.

**Query parameters**

| Param | Type | Default | Description |
|---|---|---|---|
| `transactionId` | string | — | Filter by transaction UUID |
| `accountId` | string (UUID) | — | Filter by sender or receiver account UUID |
| `event` | string | — | Filter by event name (e.g. `TRANSFER_SUCCESS`) |
| `limit` | int | `20` | Max results (1–100) |

**Example request**
```bash
curl "http://localhost:8080/api/v1/transfer/audit-logs?event=TRANSFER_SUCCESS&limit=5"
```

**200 — Success**
```json
{
  "success": true,
  "data": {
    "type": "audit-log-list",
    "attributes": {
      "count": 1,
      "items": [
        {
          "id":            "01cafe00-0000-7000-8000-000000000042",
          "event":         "TRANSFER_SUCCESS",
          "transactionId": "019612ab-c3d4-7e5f-a6b7-c8d9e0f1a2b3",
          "fromAccountId": "a1111111-1111-7111-8111-111111111111",
          "toAccountId":   "a2222222-2222-7222-8222-222222222222",
          "amount":        "250.00",
          "context":       { "from": "a1111111-1111-7111-8111-111111111111", "to": "a2222222-2222-7222-8222-222222222222", "amount": "250.00" },
          "createdAt":     "2026-04-02T10:15:30+00:00"
        }
      ]
    }
  }
}
```

---

### GET `/api/v1/audit/{entityType}/{entityId}`

Returns the audit trail for a **single domain entity** (e.g. all events logged against the sender `Account` with a given UUID). Results are ordered by `createdAt` descending.

**Path parameters**

| Param | Description |
|---|---|
| `entityType` | Domain type as stored when logging — e.g. `Account`, `Transaction` |
| `entityId` | UUID primary key of that entity (RFC 4122 string) |

**Query parameters**

| Param | Type | Default | Description |
|---|---|---|---|
| `limit` | int | `50` | Max results (1–100) |

**Example request**
```bash
curl "http://localhost:8080/api/v1/audit/Account/a1111111-1111-7111-8111-111111111111?limit=10"
```

**200 — Success**
```json
{
  "success": true,
  "data": {
    "type": "audit-trail",
    "attributes": {
      "entityType": "Account",
      "entityId": "a1111111-1111-7111-8111-111111111111",
      "count": 1,
      "items": [
        {
          "id": "019612ab-c3d4-7e5f-a6b7-c8d9e0f1a2b3",
          "action": "TRANSFER_SUCCESS",
          "entityType": "Account",
          "entityId": "a1111111-1111-7111-8111-111111111111",
          "metadata": {
            "transactionId": "019612ab-c3d4-7e5f-a6b7-c8d9e0f1a2b3",
            "from": "a1111111-1111-7111-8111-111111111111",
            "to": "a2222222-2222-7222-8222-222222222222",
            "amount": "25XXXX"
          },
          "userId": null,
          "ipAddress": null,
          "createdAt": "2026-04-06T12:00:00+00:00"
        }
      ]
    }
  }
}
```

> `userId` and `ipAddress` are populated when authentication and request context are wired in; `metadata.amount` reflects masked storage for compliance.

---

### POST `/api/v1/reversal/{transactionId}`

Reverse a successful transfer. The original receiver is debited and the original sender is credited for the same amount.

**Path parameter**

| Param | Description |
|---|---|
| `transactionId` | UUID of the original `SUCCESS` transaction to reverse |

**Headers**
```
Content-Type: application/json
Idempotency-Key: <unique-string>   (optional but recommended)
```

**Request body**
```json
{
  "reason": "Customer requested refund"
}
```

**Reversal constraints**
- Original transaction must exist and have status `SUCCESS`
- Original transaction must not itself be a reversal
- Original transaction must not already have been reversed
- Neither account may be blocked at reversal time

**Responses**

| Status | Trigger | `data.type` |
|---|---|---|
| `201 Created` | New reversal committed | `reversal` |
| `200 OK` | Duplicate `Idempotency-Key` — cached reversal returned | `reversal` |
| `400 Bad Request` | Missing or empty `reason` field | — |
| `400 Bad Request` | Original transaction not found | — |
| `422 Unprocessable` | Business rule violation | — |

**Example request**
```bash
curl -X POST http://localhost:8080/api/v1/reversal/019612ab-c3d4-7e5f-a6b7-c8d9e0f1a2b3 \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: reversal-order-abc-001' \
  -d '{"reason":"Customer requested refund"}'
```

**201 — Reversal created**
```json
{
  "success": true,
  "data": {
    "type": "reversal",
    "attributes": {
      "reversalTransactionId":  "019612ac-d4e5-7f6a-b7c8-d9e0f1a2b3c4",
      "originalTransactionId":  "019612ab-c3d4-7e5f-a6b7-c8d9e0f1a2b3",
      "status":                 "SUCCESS",
      "amount":                 "250.00",
      "reason":                 "Customer requested refund",
      "idempotencyKey":         "reversal-order-abc-001"
    }
  }
}
```

**422 — Already reversed**
```json
{
  "success": false,
  "message": "This transaction has already been reversed."
}
```

---

## Setup & Running

### Prerequisites
- Docker Desktop

### 1. Clone and configure
```bash
git clone <repo-url>
cd payments-app
cp .env.example .env        # then adjust DATABASE_URL, APP_SECRET, etc.
```

### 2. Build and start containers
```bash
docker compose up -d --build
```

Services started:

| Service | Address |
|---|---|
| `nginx` | [http://localhost:8080](http://localhost:8080) |
| `php` | PHP-FPM 8.3 (internal) |
| `db` | MySQL 8.0 on port `3306` |
| `redis` | Redis on port `6379` |

### 3. Install dependencies
```bash
docker compose exec php composer install
```

### 4. Run migrations
The schema is defined in a **single** migration file (`migrations/Version20260325000001.php`) — run once on a fresh database:

```bash
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

Optional — confirm the database matches entity mappings (should print **\[OK\] The database schema is in sync**):

```bash
docker compose exec php php bin/console doctrine:schema:validate
```

### 5. Seed the database
```bash
docker compose exec php php bin/console doctrine:fixtures:load --no-interaction
```

### Full reset (drop → migrate → seed)
```bash
docker compose exec php sh -c "
  php bin/console doctrine:schema:drop --force --full-database &&
  php bin/console doctrine:migrations:migrate --no-interaction &&
  php bin/console doctrine:fixtures:load --no-interaction
"
```

---

## Running the Test Suite

The project has two independent test suites — both run with the same `phpunit` command since `phpunit.dist.xml` picks up the entire `tests/` directory recursively.

```
tests/
├── Api/    → Integration tests  (boots full Symfony kernel + MySQL)
└── Unit/   → Unit tests         (no kernel, no database, pure PHP)
```

### Prerequisites
- Docker containers running (`docker compose up -d`)
- Test database created (one-time only):

```bash
docker compose exec php php bin/console doctrine:database:create --env=test --if-not-exists
```

The schema is recreated automatically by `setUpBeforeClass()` on the first run.

### Run the full test suite
```bash
docker compose exec php php vendor/bin/phpunit
```

If you add a new controller or route and tests return **404** for a valid URL, clear the test cache once: `rm -rf var/cache/test` (or `bin/console cache:clear --env=test`).

### Run a specific suite
```bash
# Integration tests only
docker compose exec php php vendor/bin/phpunit tests/Api

# Unit tests only (no DB required)
docker compose exec php php vendor/bin/phpunit tests/Unit
```

### Useful flags
```bash
# Readable test names with pass/fail per method
docker compose exec php php vendor/bin/phpunit --testdox

# Run a single test method
docker compose exec php php vendor/bin/phpunit --filter testSuccessfulTransfer

# Run with timing information
docker compose exec php php vendor/bin/phpunit --testdox --order-by=duration
```

### Test isolation
| Concern | How it is handled |
|---|---|
| Database state | `setUp()` truncates all tables with `FOREIGN_KEY_CHECKS=0` before each test |
| Test database | Doctrine `dbname_suffix: _test` keeps it separate from the dev DB |
| Redis / rate limiter | `payment.cache` uses in-memory array adapter in test env |
| Account fixtures | Deterministic `account_number` constants (`ACC_ALICE`, etc.); integration tests resolve `accounts.id` (UUID) from the DB for `from_account_id` / `to_account_id` |

## Contributing

- **`CONTRIBUTING.md`** — how to run tests locally, coding expectations, and pointers to load/scalability documentation.
- **`.env.example`** — copy to `.env` for local setup.

For production-style behaviour under load (Redis-backed rate limiting, pessimistic DB locks, idempotency, horizontal scaling), see [High Load & Scalability Considerations](#high-load--scalability-considerations) and [Rate Limiting](#rate-limiting).

## AI-assisted development

Development used **Anthropic** models in two roles (via **Cursor** and the Claude apps/API):

| Phase | Model | Focus |
|--------|--------|--------|
| **Planning** | **Claude Opus** | System design, API shape, rule-engine ordering, test strategy, documentation outline, risk review |
| **Implementation** | **Claude Sonnet 4.6** | Symfony/Doctrine code, migrations, PHPUnit tests, refactors, `readme.md` / `CONTRIBUTING.md` / `.env.example` |

**Cursor** was the main IDE for applying edits, running Dockerized PHPUnit, and iterating on diffs.

Further detail — **prompts you can reuse**, ownership notes, and a fuller tool list: **[`docs/AI_ASSISTANCE.md`](docs/AI_ASSISTANCE.md)**.

---

## Seeded Test Data

After running fixtures (`doctrine:fixtures:load`) you will have:

| User | Account type | Balance | Status |
|---|---|---|---|
| Alice Johnson (`alice@example.com`) | SAVINGS | 1,000.00 | Active |
| Bob Smith (`bob@example.com`) | CURRENT | 1,125.00 | Active |
| Charlie Brown (`charlie@example.com`) | SAVINGS | 300.00 | **Blocked** |
| Dave Wilson (`dave@example.com`) | SALARY | 500.00 | Active |

Pre-seeded transactions:
- Alice → Bob `250.00` (SUCCESS · idempotency key: `idem-alice-bob-001`)
- Charlie → Alice `500.00` (FAILED — sender blocked)
- Dave → Alice `100.00` (SUCCESS · idempotency key: `idem-dave-alice-001`)

Retrieve account numbers after seeding:
```bash
docker compose exec db mysql -uroot -proot paysera \
  -e "SELECT id, account_number, account_type, balance, is_blocked FROM accounts;"
```

---

## Transfer Flow (Step by Step)

```
 1. [Controller]   Read Idempotency-Key header + JSON body
 2. [Validator]    Validate from_account_id, to_account_id, amount
 3. [Service]      BEGIN database transaction
 4. [Service]      Check idempotencyKey → return existing if duplicate
 5. [Repository]   SELECT FOR UPDATE both accounts (pessimistic lock)
 6. [RuleEngine]   Run rules in priority order:
                     50 → DifferentAccountsRule
                     40 → AccountNotBlockedRule
                     30 → SufficientBalanceRule
                     20 → FraudDetectionRule
                     10 → TransferLimitRule
 7. [Service]      Create Transaction entity (PENDING)
 8. [Service]      Debit sender balance   (bcsub — scale 2)
 9. [Service]      Credit receiver balance (bcadd — scale 2)
10. [Service]      Persist two LedgerEntry rows (DEBIT + CREDIT)
11. [Service]      Mark Transaction → SUCCESS
12. [AuditLog]     Write TRANSFER_SUCCESS (inside transaction)
13. [Service]      em->flush() + connection->commit()
14. [Controller]   Serialize via ApiResponse → return HTTP 201

    On failure:
      → ROLLBACK all ORM changes
      → Write ACCOUNT_BLOCKED or FRAUD_ALERT (post-rollback, auto-commit DBAL)
      → Write TRANSFER_FAILED (post-rollback, auto-commit DBAL)
      → Re-throw → ApiExceptionSubscriber → JSON error response
```

---

## Rule Engine

Rules are self-registering through Symfony's service tag system. To add a new rule:

1. Create a class in `src/RuleEngine/TransferRules/`
2. Implement `TransferRuleInterface`
3. Add `#[AutoconfigureTag('transfer.rule', ['priority' => N])]`

```php
#[AutoconfigureTag('transfer.rule', ['priority' => 35])]
final class DailyLimitRule implements TransferRuleInterface
{
    public function check(Account $fromAccount, Account $toAccount, string $amount): void
    {
        // fetch today's total from the DB and compare ...
        if (bccomp($todayTotal, $dailyLimit, 2) === 1) {
            throw new \DomainException('Daily transfer limit exceeded.');
        }
    }
}
```

No other file needs to change. Symfony's tagged iterator picks it up and inserts it into the execution chain at the correct priority automatically.

---

## Error Handling

`ApiExceptionSubscriber` listens on `kernel.exception` and maps every throwable to a typed JSON response. The check order matters — `HttpExceptionInterface` is tested **before** `\RuntimeException` because Symfony's `HttpException` (parent of `NotFoundHttpException`, `MethodNotAllowedHttpException`, etc.) extends `\RuntimeException`.

| Exception type | HTTP status | Body shape |
|---|---|---|
| `ValidationException` | 400 | `{ "success": false, "message": "Validation failed.", "errors": { ... } }` |
| `\InvalidArgumentException` | 400 | `{ "success": false, "message": "..." }` |
| `HttpExceptionInterface` | own code | `{ "success": false, "message": "..." }` |
| `\DomainException` | 422 | `{ "success": false, "message": "..." }` |
| `\RuntimeException` | 422 | `{ "success": false, "message": "..." }` |
| Anything else (prod) | 500 | `{ "success": false, "message": "An unexpected error occurred." }` |
| Anything else (dev) | 500 | `{ "success": false, "message": "...", "exception": "...", "file": "...", "trace": [...] }` |

---

## Rate Limiting

`POST /api/v1/transfer` is protected by a **sliding-window rate limiter** implemented in `RateLimitSubscriber` using `symfony/rate-limiter` backed by the `payment.cache` Redis pool.

### How it works

The subscriber fires on `kernel.request` at priority 20 — before the controller, before security, before the database is touched. Over-limit requests are rejected in microseconds.

```
  Incoming POST /api/v1/transfer
           │
           ▼   kernel.request (priority 20)
  ┌────────────────────────────┐
  │   RateLimitSubscriber      │
  │   key  = client IP         │
  │   policy = sliding_window  │
  │   store = Redis (cache)    │
  └─────────────┬──────────────┘
                │  tokens left?
         ┌──────┴──────┐
        Yes           No
         │             │
         ▼             ▼
    continue      HTTP 429
    to controller Retry-After
```

### Configuration

All parameters live in environment variables — tune without touching code:

```dotenv
# .env  (or .env.local for local overrides)
RATE_LIMIT_TRANSFER_MAX_REQUESTS=10
RATE_LIMIT_TRANSFER_INTERVAL="1 minute"
```

```yaml
# config/packages/rate_limiter.yaml
framework:
    rate_limiter:
        transfer_api:
            policy:     sliding_window
            limit:      '%env(int:RATE_LIMIT_TRANSFER_MAX_REQUESTS)%'
            interval:   '%env(RATE_LIMIT_TRANSFER_INTERVAL)%'
            cache_pool: payment.cache   # Redis-backed
```

### Rate-limit key strategy

Requests are keyed by **client IP** by default. To switch to per-account keying (more precise for authenticated APIs), update two lines in `RateLimitSubscriber::onKernelRequest()` — the inline comment shows exactly how.

### Suggested limits per environment

| Environment | Setting |
|---|---|
| Development | `10 / 1 minute` (default) |
| Staging | `30 / 1 minute` |
| Production | `60 / 1 minute` or tighter per SLA |
| Tests | `10 000 / 1 minute` (overridden in `rate_limiter.yaml when@test`) |

---

## High Load & Scalability Considerations

### Transaction Consistency

All transfers are executed within a single database transaction to ensure atomicity.

This guarantees that:
- Debit and credit operations either both succeed or both fail
- No partial transactions are ever committed to the database
- Account balances, ledger entries, and the transaction record always remain in a consistent state

```
BEGIN
  ├─ Acquire SELECT FOR UPDATE locks on both accounts
  ├─ Run business rules (blocked, balance, fraud, limits)
  ├─ INSERT transaction (PENDING)
  ├─ UPDATE from-account balance  (debit)
  ├─ UPDATE to-account   balance  (credit)
  ├─ INSERT ledger_entry DEBIT
  ├─ INSERT ledger_entry CREDIT
  ├─ UPDATE transaction → SUCCESS
  └─ INSERT audit_log TRANSFER_SUCCESS
COMMIT  ← all changes land atomically, or none do
```

If any step throws — including the ORM flush — the entire unit of work is rolled back. The only exception is the `TRANSFER_FAILED` audit entry, which is written via raw DBAL in auto-commit mode **after** the rollback, ensuring failure events are always persisted for forensic purposes regardless of what went wrong.

> **Real-world note:** In a distributed system spanning multiple services or databases, a single-transaction approach is not always possible. The industry-standard pattern is to use a **suspense account** (a clearing/escrow ledger entry) combined with a **retry-with-idempotency** mechanism: funds are first moved to the suspense account, confirmed, then released to the recipient. Failed legs can be reconciled automatically by a background job that re-drives the incomplete transfer using the original idempotency key. This application already lays the groundwork for that pattern through its idempotency-key support and double-entry ledger.

### Pessimistic Locking Prevents Race Conditions
Before any balance is read or modified, both the sender and receiver accounts are locked with `SELECT FOR UPDATE` (`LOCK_MODE_PESSIMISTIC_WRITE` in Doctrine). This guarantees that two concurrent transfers from the same account are serialised at the database level — the second request waits for the first to commit before reading the updated balance, preventing double-spend.

```
Request A: SELECT ... FOR UPDATE (account 1) → debit 500 → COMMIT
Request B:               waits... → SELECT (sees updated balance) → check passes or fails correctly
```

### Idempotency Prevents Duplicate Processing
Clients supply an `Idempotency-Key` header. Before acquiring any locks or running business rules, `TransferService` checks whether a transaction with that key already exists and, if so, returns it immediately without re-processing. This makes the API safe to retry on network timeouts without creating duplicate charges.

### Redis for Caching and Rate Limiting
A dedicated `payment.cache` Redis pool is configured in `config/packages/cache.yaml`. It backs two production concerns:

1. **Rate limiting** — the `transfer_api` sliding-window limiter stores per-IP token counts in Redis, making limits consistent across all PHP-FPM instances. Entries expire automatically when the window slides past.
2. **Application cache** — the pool can be injected into any service to cache expensive reads (e.g. account limits, exchange rates) without hitting MySQL on every request.

### Stateless API Enables Horizontal Scaling
The API holds no in-process state between requests. All state lives in MySQL (accounts, transactions, ledger) and Redis (cache, sessions). Multiple PHP-FPM containers can sit behind an Nginx load balancer without sticky sessions — additional nodes can be added with zero application changes.

```
                    ┌─────────────┐
                    │   Nginx     │  load balancer
                    └──────┬──────┘
           ┌───────────────┼───────────────┐
           ▼               ▼               ▼
      ┌─────────┐   ┌─────────┐   ┌─────────┐
      │ php-fpm │   │ php-fpm │   │ php-fpm │   stateless workers
      └────┬────┘   └────┬────┘   └────┬────┘
           └─────────────┼─────────────┘
                         ▼
              ┌──────────────────────┐
              │      MySQL 8.0         │  source of truth · row locks
              └───────────┬────────────┘
                          │
              ┌───────────▼────────────┐
              │        Redis           │  cache · rate limit · sessions
              └────────────────────────┘
```

### Database Indexing on Critical Columns
The schema applies targeted indexes on columns used in hot-path queries:

| Table | Index | Purpose |
|---|---|---|
| `users` | `UNIQ_users_email` | O(1) login lookup by email |
| `accounts` | `idx_account_user_id` | Look up accounts by owner |
| `accounts` | PK `id` | Transfer API resolves accounts by UUID (`findForUpdate`) |
| `accounts` | `UNIQ_account_number` | O(1) lookup by public account number (display, fixtures, support) |
| `transactions` | `idx_transaction_from_account` | Outgoing transfers per account |
| `transactions` | `idx_transaction_to_account` | Incoming transfers per account |
| `transactions` | `idx_transaction_status` | Filter by PENDING / SUCCESS / FAILED |
| `transactions` | `IDX_EAA81A4C76500E2D` | Lookup by `reference_transaction_id` (original transfer when resolving reversals) |
| `transactions` | `UNIQ_transaction_idempotency_key` | O(1) duplicate-key detection |
| `ledger_entries` | `idx_ledger_account_id` | All ledger lines for one account |
| `ledger_entries` | `idx_ledger_transaction_id` | Both lines (DEBIT + CREDIT) tied to a `transaction_id` |
| `ledger_entries` | `idx_ledger_account_timeline` | Composite `(account_id, created_at)` for chronological statements |
| `audit_logs` | `idx_audit_event` | Filter audit logs by event type |
| `audit_logs` | `idx_audit_transaction` | Join audit entries to a transaction |
| `audit_logs` | `idx_audit_created_at` | Time-range queries on the audit trail |
| `audit_logs` | `idx_audit_entity` | Composite `(entity_type, entity_id)` for entity-scoped audit API |

### bcmath for Monetary Precision
All arithmetic is performed with PHP's `bcmath` extension at scale 2 rather than native floats. This eliminates floating-point rounding errors when debiting and crediting balances — critical for financial correctness at any volume.

---

## What Is Missing / Future Scope

### Authentication & Authorisation
- No JWT / OAuth2 token authentication implemented
- No ownership check — any caller can transfer from any account UUID they know
- Suggested: add `symfony/lexik-jwt-authentication-bundle`, tie accounts to the authenticated user

### Account Management API
- No endpoints to create users, create accounts, or view account details
- Suggested: `POST /api/v1/accounts`, `GET /api/v1/accounts/{number}`

### Transaction History per Account
- No endpoint to list transactions for a specific account
- `TransactionRepository::findRecentByAccount()` exists but is not yet exposed via the API

### Currency Conversion
- All transfers assume the same currency (enforced by `FraudDetectionRule`)
- Suggested: integrate an exchange-rate service and allow cross-currency transfers with an explicit conversion step

### Daily / Monthly Transfer Limits
- Current limits are per-transaction only
- Suggested: add a `DailyLimitRule` that aggregates today's transfers and rejects if over threshold

### Cursor-Based Pagination
- Audit log endpoint returns up to 100 records with no cursor or offset pagination
- Suggested: add cursor-based pagination for efficient traversal of large result sets

### Webhook / Event Notifications
- No outbound notifications on transfer completion
- Suggested: dispatch a Symfony Messenger message on `TRANSFER_SUCCESS` for downstream consumers

### Asynchronous Processing
- Transfers are fully synchronous — the HTTP response waits for the DB commit
- Suggested: for high-throughput scenarios, accept the request and process via Symfony Messenger queue, returning a `202 Accepted` with a polling URL

### Soft Deletes & Account Closure
- Accounts can only be blocked, not permanently closed
- Suggested: add `closedAt` timestamp and a `ClosedAccountRule`

### Admin / Back-Office API
- No admin endpoints to manage users, block accounts, or view all audit logs
- Suggested: a separate `AdminController` with `ROLE_ADMIN` role-based access control
