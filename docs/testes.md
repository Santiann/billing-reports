# Tests

[← README](../README.md)

- [Tests](#tests)
- [End-to-end tests](#end-to-end-tests)

## Tests

```bash
make test                                   # docker compose exec php php artisan test
```

The suite runs **inside the container** because it runs on **MySQL**, not SQLite.
Laravel's skeleton arrives pointed at `sqlite/:memory:`, and that would be a
serious problem in this project: the central rule is that a billing's updated
value be computable in SQL, and the mandatory consistency test compares
`InterestCalculator`'s SQL face against its PHP face. On SQLite it would be
validating a different engine — `POW()` does not even exist by default, and
`DATEDIFF()` and `DECIMAL` precision both diverge.

```
OK (283 tests, 1070 assertions)
```

### Coverage

```bash
make coverage                               # docker compose exec php php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text
```

| | |
|---|---|
| **Lines** | **97.44%** (1333/1368) |
| Methods | 90.87% (189/208) |
| Classes | 81.36% (48/59) |

It uses **pcov**, not xdebug: pcov exists only for coverage and costs a fraction
of the time. It stays off by default (`pcov.enabled = 0`) so it does not weigh on
normal runs, and is switched on from the command line.

The `-d` has to go directly on `phpunit` because `artisan test --coverage` runs
PHPUnit in a subprocess and the flag does not propagate — it answers "No code
coverage driver available" even with the extension loaded.

An earlier point in the project closed at 99.84% of lines over 628. The code under
coverage then tripled — 1,368 lines — and coverage dropped 2.4 points. The 35
uncovered lines are not scattered: they concentrate in the newer classes, and
almost all of them are **defensive branches**.

| Class | Lines |
|---|---|
| `IdempotentRequest` | 83.02% (44/53) |
| `IdempotencyStore` | 85.45% (47/55) |
| `CsvReader` | 90.20% (46/51) |
| `BillingAudit` | 91.67% (11/12) |
| `ExplainReportCommand` | 95.37% (103/108) |
| `BillingAuditObserver` | 95.45% (21/22) |
| `BillingAuditResource` | 96.15% (25/26) |
| `BillingCsvImport` | 98.00% (98/100) |
| `DashboardQuery` | 98.55% (68/69) |
| `BillingReportCsvExport` | 98.63% (72/73) |

What is uncovered, named:

- **Giving the idempotency key back on a 500.** Covering it would require forcing
  a server error in the middle of a request carrying a key — staging that would
  test the test, not the system.
- **The lottery-based cleanup of expired keys.** It runs on a one-in-two-hundred
  chance, on purpose; a test that forced it would have to pin the draw, and would
  then be asserting about the pinning.
- **The observers' `deleted()`.** Nothing in the system deletes a billing. The
  hook exists for the day something does, and that is what leaves it uncovered.
- **The streamed-response guard and the key's 255-character cap.** Two defences
  for future use of the idempotency middleware, which today applies to two routes
  that do not export files.
- **The unknown field label in the trail.** It only appears if a new column
  arrives without a label — it exists so the trail does not lose the change in
  silence.
- **The CSV export's `flush()`**, every 500 rows written: it would require
  creating 500 billings to assert a side effect with no observable result.

It is the same decision throughout, with more cases: chasing the last percentage
point here would produce tests that prove staging. What those branches have in
common is being the error path — and the error path that matters, the one where
the system **refuses** the operation, does have tests:
[without the trail there is no change](modulos.md#atômica-sem-trilha-sem-alteração),
[a repeated key returns the first result](modulos.md#idempotência-no-pagamento),
[the read-only role does not write](modulos.md#a-barreira-é-o-backend-não-a-tela).

The coverage report is what exposed three real gaps, all since closed: the
report's `status=pending` filter was never exercised (the tests used `paid` and
`overdue` and skipped the third), three of the export's header labels were never
generated, and the calculator's defensive branch for a paid billing with no
payment date had no test.

### The seeder's test emits no DDL

`BillingVolumeSeederTest` runs the real seeder over a sample of 600 billings and
cleans nothing up afterwards: what undoes it is `RefreshDatabase`'s rollback.
Cleaning up with `TRUNCATE` would be the obvious route and would cost dearly.

`TRUNCATE` is DDL, and in MySQL DDL performs an **implicit commit**. Laravel
notices the test's transaction is gone and marks
`RefreshDatabaseState::$migrated = false` — which triggers a full `migrate:fresh`
before **every following test**, not just this class's. The code is at
`RefreshDatabase.php:158`, and the effect was measured here:

| | class duration |
|---|---|
| With `TRUNCATE` in the teardown | 360s (6 tests, ~50s of `migrate:fresh` each) |
| With no DDL at all | 62s (60s for the single `migrate:fresh` + 0.3s per test) |

It is the same reason the seeder bails out early when there is nothing to
truncate: on an empty table, the `TRUNCATE` would be all cost.

### The test database

The suite's database is `billing_test`, kept apart from the development one
because `RefreshDatabase` drops and recreates the schema on every run. It is
created on MySQL's first init by
`docker/mysql/init/01-create-test-database.sql`. On a volume that already exists
the init script does not run — apply the file by hand:

```bash
docker compose exec -T mysql mysql -u root -proot < docker/mysql/init/01-create-test-database.sql
```

---

## End-to-end tests

```bash
make e2e          # docker compose --profile e2e run --rm e2e
```

Playwright covering what the brief asks for as a frontend differentiator: login,
creating a customer, recording a payment — plus the reversal — and exporting.
**10 tests, 3.4 minutes.**

They run against the **Compose stack**, and not against a server Playwright
brings up. That is deliberate: what we want to prove is the application as it is
delivered — Next talking to nginx by service name, the session in an httpOnly
cookie, a real MySQL. A Playwright `webServer` would start an isolated Next with
no backend, and the creation and payment flows would not exist.

| Decision | Why |
|---|---|
| A Compose service behind the `e2e` profile | `docker compose up -d` should not start something that runs and exits |
| The official Playwright image | the frontend's is Alpine, and the project's browsers are compiled against glibc |
| One worker, no parallelism | the tests write to the SAME database; two creations at once would contend over the document's unique index |
| A unique suffix per run | the database is not cleaned between runs, and without it the day's second round would fail on a conflict |
| Outside CI | it would require MySQL, php-fpm, nginx and Next on the runner; CI runs the suite and the lint |

Two tests run in a **360px** viewport and assert the absence of horizontal
scrolling — on the public screens and on the authenticated ones. It is the brief's
acceptance criterion for small screens, verified by a test rather than by a
screenshot.

### Four real problems it found

This is the part that justifies the suite. On the first run, **8 of the 10 tests
failed** — and none of them because of Playwright.

**1. Next was blocking hydration, and the log said so.** The tests arrive via
`http://frontend:3000`, the service name. Next refuses requests for development
resources coming from a host other than the one it started on, HMR was denied,
hydration never completed and **no form responded to a click**. The container
printed the option by name — `allowedDevOrigins` — and I had not read the log.
Fixed in `next.config.ts`, where it applies in development only.

**2. The pay button was dead outside `localhost`.** `crypto.randomUUID()`, used to
draw the idempotency key, exists **only in a secure context**: HTTPS, or
`localhost` by browser exception. Served over plain HTTP on any other host — an IP
on the internal network, a service name — the function is `undefined`, the handler
died with a `TypeError` before submitting and the screen gave no warning at all.
Measured in the container: `isSecureContext: false`, `randomUUID: undefined`,
`getRandomValues: function`. The fix builds the v4 UUID with `getRandomValues`,
which carries no such restriction, and keeps cryptographic randomness on both
paths.

This is the kind of defect no unit test finds and no click on `localhost` reveals.

**3. The screen showed the wrong success message.** The code `sucesso=criado`
served both the customer and the billing, and both listings render the same alert
component: creating a **billing** displayed *"Cliente cadastrado com sucesso"*.
There are two codes now, and `editado` still serves both because its message names
no entity.

**4. The right thing to wait for is not time, it is hydration.** The login form is
controlled (`value` + `onChange`). Before React takes over, filling it writes a
value into the DOM that hydration discards, and clicking fires the form's
**native** submit, which reloads the page clean — which is exactly what the
failure screenshot showed. The tests wait for the key React DOM hangs on the node
(`__reactProps$`) once it starts handling its events. It is React internal API,
which is why it only appears in the tests; the alternative was `waitForTimeout`,
which trades a race for a bet.

### Two things the suite demanded of the environment

**Warming up the routes.** The target is the development server, which compiles
each route on first visit — and the first visit is precisely what these tests
make. Three tests failed on time, with the "Entrando…" button still disabled in
the screenshot. A `globalSetup` signs in once and visits the screens before the
suite, so each test measures the application rather than the compiler.

**Ignoring Playwright's output in ESLint.** The HTML report embeds a minified
bundle, and `eslint` started analysing it: 3,054 problems in code that is not
ours.
