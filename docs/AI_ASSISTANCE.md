# AI-assisted development

This document records **how AI tools were used** in building and refining this project, and provides **three copy-paste prompts** that together cover the full scope: architecture, implementation, testing, documentation, and release polish.

AI assistance is a productivity multiplier; **you remain responsible** for the codebase—architecture, correctness on the critical path, and the ability to explain and extend it.

---

## Tools used

| Tool | Role |
|------|------|
| **Claude Opus** | Planning: architecture, API design, rule-engine ordering, test strategy, doc outlines, review |
| **Claude Sonnet 4.6** | Implementation: application code, tests, refactors, README/CONTRIBUTING (typically via **Cursor**) |
| **Cursor** (IDE + Agent / Composer) | Editing, multi-file changes, running PHPUnit in Docker, guided iteration |
| **PHPUnit** | Automated verification (integration + unit); run locally or via `docker compose exec php …` |
| **Docker Compose** | Reproducible stack: PHP-FPM, Nginx, MySQL, Redis |

---

## What AI was used for (summary)

- **Architecture & implementation** — Symfony structure, Doctrine entities, transfer/reversal services, rule engine, idempotency, pessimistic locking, audit logging, rate limiting.
- **Tests** — Integration tests (`WebTestCase`) for HTTP + DB; unit tests for validator and rules; edge cases (validation, business rules, audit endpoints).
- **Documentation** — README sections, API examples, setup instructions, contributing notes, `.env.example`.
- **Consistency passes** — Aligning API payloads (e.g. account UUIDs), migration vs entities, README accuracy, PHPDoc on public controllers/DTOs.

---

## Codebase health (last check)

Run these yourself before tagging a release or merging main work:

```bash
docker compose exec php php bin/console doctrine:schema:validate --env=test
docker compose exec php php vendor/bin/phpunit
```

Expected: mapping OK, schema in sync, **all tests green**.

---

## Three comprehensive prompts

Use these as **templates**. Adapt repository paths and names to your repo.

---

### Prompt 1 — Build the secure fund-transfer API (full stack)

You are a senior PHP engineer. Build a **production-minded** payments API in **Symfony 6.4**, **PHP 8.3**, **MySQL 8**, **Redis**, Dockerised.

**Core**

- `POST /api/v1/transfer` with JSON body: `from_account_id`, `to_account_id`, `amount` (account primary keys as UUIDs). Validate UUIDs and amount (positive decimal, max 2 decimal places, bcmath only for money).
- Atomic **database transaction** per transfer: pessimistic `SELECT FOR UPDATE` on both accounts, then debit/credit balances.
- **Double-entry bookkeeping**: exactly two `LedgerEntry` rows (DEBIT / CREDIT) with `balance_after`.
- **Idempotency** via optional `Idempotency-Key` header; return existing transaction if key repeats.
- **Rule engine** (tagged services, priorities): different accounts; account not blocked; user not blocked if modelled; sufficient balance; fraud heuristic (e.g. large amount); per-account-type transfer limit.
- **Audit trail**: success and failure events; failure audits must survive rollback (e.g. DBAL insert outside rolled-back transaction where appropriate).
- Unified JSON **envelope** for success and errors.

**Ops / scale story**

- Redis-backed **rate limiting** on the transfer route (config via env).
- README: locking, idempotency, horizontal scaling, bcmath, indexing.

**Data**

- Doctrine ORM, UUID PKs where appropriate, migrations, fixtures for demo data.

**Deliverables**

- Source under `src/`, tests under `tests/`, `readme.md`, Docker Compose, `.env.example`, `CONTRIBUTING.md` with how to run PHPUnit.

Implement with clean layering: controllers → DTO/validator → services → repositories → rules. No floating-point money.

---

### Prompt 2 — Tests, PHPDocs, and hardening pass

Review the existing Symfony payments project in this workspace. **Do not change behaviour** unless fixing a bug.

**Testing**

- Ensure **integration tests** cover: validation errors (400), business rule failures (422), successful transfer (201), idempotency replay (200), ledger + audit side effects, sequential transfers, audit list and entity-scoped audit endpoints if present.
- Ensure **unit tests** cover the transfer request validator and every rule class in isolation (mocks/stubs, no DB).
- Run `doctrine:schema:validate --env=test` and fix mapping/schema drift.

**Documentation**

- Add or complete **PHPDoc** on public DTOs, API controllers (`@param`, `@return`, `@throws` where useful), and critical services.
- Keep `readme.md` aligned with the real request/response shapes and env vars.

**Financial correctness**

- Confirm all balance math uses **bcmath**; grep for `(float)` on money and remove unsafe patterns.

Output a short summary of files touched and any remaining risks.

---

### Prompt 3 — Final review: consistency, docs, and release checklist

Perform a **release readiness** pass on this repository.

**Consistency**

- Grep for outdated API field names (e.g. old account-number-based transfer body vs current UUID fields) in `readme.md`, `CONTRIBUTING.md`, and comments.
- Confirm single migration story (or document multiple migrations clearly).
- Align `composer.json` PHP version with Docker/README.

**Documentation**

- Table of contents matches headings; setup steps work from a clean clone (`cp .env.example .env`, compose up, migrate, run tests).
- Add or update **`docs/AI_ASSISTANCE.md`**: tools used, how AI helped, and prompts used.

**Quality bar**

- Run full PHPUnit; fix failures.
- Draft 3–5 technical deep-dive topics: idempotency, locking, audit on failure, rate limits, plausible next steps (auth, async, etc.).

Keep changes minimal and purposeful—no drive-by refactors.

---

## Ownership

These prompts are **templates** for documentation and learning. Treat generated or suggested code as **yours to verify**: walk through `TransferService`, rule order, and at least one integration test path until you could maintain or debug it independently.
