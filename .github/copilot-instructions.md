# Database safety and test policy

- Preserve the user's local PriceBuddy database and Docker volumes. Treat them as production-like data.
- Do not create, modify, or run automated tests unless the user explicitly asks for test work.
- Never run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, `RefreshDatabase`, or any equivalent destructive database operation against the `pricebuddy` database.
- Never run the test suite inside the normal `app` container or with the project's regular `.env` database settings.
- Tests that use a database must use a dedicated isolated database such as `pricebuddy_test`, `tests_db`, or an in-memory SQLite database.
- Before running any database-backed test, verify the effective runtime database name. If it is `pricebuddy`, empty, unavailable, or cannot be verified, abort the test and report the safety issue instead.
- Do not assume `APP_ENV=testing` makes the database safe. Confirm the effective connection, host, and database name.
- A test may use `RefreshDatabase` only after an isolated test database has been created and verified. Never fall back to the main database when the test database is unavailable.
- Do not delete or recreate Docker volumes as part of testing, troubleshooting, builds, or application restarts.
- Prefer read-only diagnostics. Ask the user before any operation that can delete or overwrite application data.
