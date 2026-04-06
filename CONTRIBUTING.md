# Contributing

For **AI tools, disclosure, and copy-paste prompts** used on this project, see [`docs/AI_ASSISTANCE.md`](docs/AI_ASSISTANCE.md).

## Tests (required for changes)

This project uses **PHPUnit** with:

- **Integration tests** (`tests/Api/`) — full Symfony kernel, real HTTP, MySQL; schema is created via Doctrine metadata in `setUpBeforeClass()`.
- **Unit tests** (`tests/Unit/`) — no database, no kernel.

### Local (Docker)

From the project root (with containers up):

```bash
docker compose exec php php bin/console doctrine:database:create --env=test --if-not-exists
docker compose exec php php vendor/bin/phpunit
```

Test database name is typically `paysera_test` (see `config/packages/doctrine.yaml` `dbname_suffix` when `@test`).

## Code style

- Prefer **strict types** and explicit types on new code.
- Use **PHPDoc** on public APIs (`@param`, `@return`, `@throws` where helpful).
- Monetary values: **bcmath** only (no float arithmetic for money).

## Load / scalability notes

Implementation details (rate limiting, locking, idempotency) are documented in **`readme.md`** under *Rate Limiting* and *High Load & Scalability Considerations*. Prefer extending those sections if you add benchmarks or load-test notes.
