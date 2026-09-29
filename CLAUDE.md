# Billing report generator

A billing application with authentication and a charges report, designed for
tables in the millions of rows.

This file steers the AI agents used in development. It is deliberately under
version control alongside the code: the instructions that produced the project are
part of it, and they changed when measurement contradicted what they said.

---

## Stack — fixed

- **Backend:** PHP 8.3 + Laravel, REST API, Sanctum (bearer token)
- **Frontend:** Next.js (App Router) + TypeScript
- **Database:** MySQL 8
- **Infrastructure:** Docker + Docker Compose (mysql, php-fpm, nginx, frontend)

Nothing above is substitutable. These are the brief's requirements, not
preferences.

---

## The rule that governs the architecture

**The updated value of an overdue billing has to be computable in SQL.**

The report needs to sort by updated value and sum the total interest over the
entire filtered set. If the calculation existed only in PHP, either of those two
operations would force loading the whole result into memory — which the brief
explicitly rules out.

The consequences, and they are mandatory:

- `App\Domain\Billing\InterestCalculator` is the rule's only source and has two
  faces: `updatedAmountSql()` and `interestAmountSql()`, used in `selectRaw` in the
  listing and in the aggregations, and `for(Billing $billing)`, used to display a
  single billing. `overdueSql()` lives alongside them because "overdue" is the same
  rule seen from another angle.
- The reference date **comes down from PHP**, never `CURDATE()`. `travelTo()` moves
  PHP's clock and not MySQL's: with `CURDATE()` embedded, the consistency test
  would compare frozen time against real time and would never close. It is also
  what makes it possible to compute interest at the payment date.
- There is a test that runs the same matrix of cases through both faces and asserts
  equality down to the cent. **Without that test the work is incomplete** — it is
  what upholds the requirement of "a consistent result across every screen and
  report".
- Compound interest: `original_amount * POW(1 + monthly_rate, days_late / 30)`.
- A paid billing accrues no interest. The interest freezes at the payment date and
  the displayed value comes from the columns written at the moment of payment,
  never from a recompute.

---

## Next.js — two API origins

Inside Compose there are two fetch contexts and they do **not** use the same URL:

| Context | Base | Variable |
|---|---|---|
| Server Components, Route Handlers | `http://backend` | `API_URL_INTERNAL` |
| Code running in the browser | `http://localhost:8000` | `NEXT_PUBLIC_API_URL` |

Using the wrong variable is this project's most likely bug and it only shows up
inside Docker. Every fetch goes through `lib/api.ts`, which resolves the base from
the context. No `fetch` with a literal URL scattered across components.

In Compose, `backend` is the **nginx** service — it is what answers HTTP. php-fpm
is called `php`, because it speaks FastCGI and not HTTP. Do not rename them: the
`http://backend` in the table above depends on that choice.

---

## Authentication

The Sanctum token in an **httpOnly** cookie, never in `localStorage`.

- `POST /api/auth/login` is a Next Route Handler: it calls Laravel, receives the
  token, writes the httpOnly cookie and does not return the token to the browser.
- `middleware.ts` protects the app's routes by the cookie's presence.
- Server Components read the cookie and send `Authorization: Bearer` to Laravel.
- **PDF and CSV downloads go through a Route Handler**, which attaches the token
  and streams Laravel's response. The browser does not have the token, so it cannot
  call the export endpoint directly. The body is passed through unread: consuming
  the stream to resend it would hold the file in memory.
- **Mutations use a Server Action**, not a Route Handler. Same reason — the server
  is what talks to Laravel — but the Action returns the validation errors field by
  field to the form, rather than a generic message. Route Handlers are for what the
  browser needs to navigate to or download.

---

## Performance — non-negotiable

- Pagination, filtering and sorting always in the database. No `->get()` followed
  by filtering or sorting a collection.
- CSV export with `lazy()` + `StreamedResponse`, writing row by row. Never assemble
  the complete set in an array.
- PDF export is limited by nature: the document is assembled whole before it
  exists, so there is no streaming. A row cap with a 422 above it, pointing at the
  CSV. A decision to document in `docs/performance.md`, not a failure to hide.
- **The cap is 1,000, not 5,000.** The original value was an estimate and did not
  survive measurement: dompdf consumes 420 MB for a thousand rows, 1,164 MB for two
  thousand and blows past 3 GB at five thousand — the growth is superlinear because
  it assembles the whole table's frame tree before paginating. It lives in
  `config/reports.php` with the measured curve recorded beside it.
- Totals in a separate aggregation query, over the entire filtered set. Never sum
  the current page.
- **Indexes:** the equality column before the range column. Since the user chooses
  which of the three dates defines the period, each one needs its own index, and the
  variant with `customer_id` in front covers filtering by customer. Every index
  created goes into `docs/performance.md` alongside the query it serves.
- The seeder generates real volume (2M+ billings) through batch inserts with
  chunking, not a factory record by record.

---

## Tests

See `.claude/skills/laravel-report-tests/SKILL.md` for the conventions and this
project's specific traps (frozen time, streamed responses, asserting about a PDF).

**The suite runs on MySQL, not SQLite.** Laravel's skeleton arrives pointed at
`sqlite/:memory:`, and that would make the consistency test impossible: on SQLite
the SQL face would be validating a different engine — `POW()` does not even exist
by default. The database is `billing_test`, created by the container's init, and
the consequence is that the suite runs inside it:

```
docker compose exec php php artisan test
```

General rule: before writing business rule code, write the test it has to pass.

**End to end with Playwright**, against the running stack:

```
make e2e
```

Its own Compose service, behind the `e2e` profile so it does not start with the
rest. It runs against the application as it is delivered — Next talking to nginx by
service name, the session in a cookie, a real MySQL — and not against a server
Playwright brings up. It stays out of CI on purpose: that would require bringing
the whole stack up on the runner.

**CI in `.github/workflows/ci.yml`**: two parallel jobs running what `make test`
and `make lint` do locally. A push that breaks the typecheck or the suite shows up
red on the PR.

---

## Commits

Small, semantic, one per responsibility — the history is part of the work. One
commit at a time, and the project goes up after each one.

### Stage 1 — delivered

```
chore: scaffold laravel and next apps
chore: add docker environment
chore: add ai agent configuration
feat: add authentication structure
feat: create database schema and factories
feat: create customers module
feat: create billing module
feat: add overdue interest calculation
feat: create billing report filters
feat: add report indexes
feat: add csv report export
feat: add pdf report export
test: add billing interest tests
docs: update project instructions
```

### Stage 2 — extras and polish

Three decisions hold for the whole stage and are not reopened at each commit:

- **The same branch.** Everything goes to `main`. No branch per block: the stage
  grows on top of what is already delivered.
- **History preserved.** Stage 1's 14 commits are not rewritten: they keep the
  `Co-Authored-By` trailer, and the new ones carry it too. No rebase, squash or
  amend over what has already been pushed.
- **Stage 1 is the foundation, not a draft.** What is already delivered only
  changes when this stage's commit calls for it — and then the change is the
  commit's subject.

Order in blocks. One block does not run into the next: each commit stops, shows the
diff and waits.

**Blocks A to F — delivered.** The list below is what happened, not what was
planned: four commits were not in the plan and came out of findings during the
stage, and they are marked.

```
A — foundation
docs: plan stage two
chore: add project skills
fix: seed paid billings with frozen interest
fix: agree on the half cent in both faces          <- finding: 1 divergence in 13,654
feat: add global error and loading boundaries
chore: add makefile
refactor: trim excessive comments

B — API documentation
feat: add openapi specification
feat: serve api documentation at root

C — visual rework
feat: add design system foundation
refactor: restyle authentication and app shell
refactor: restyle customers and billings
refactor: restyle billing report
feat: add dashboard
chore: add readme and skill discovery skills

D — importing and the landing page
feat: add customer csv import
feat: add billing csv import
feat: add public landing page

E — technical extras
feat: add role based access control
feat: add payment idempotency
feat: add billing audit trail
feat: add payment reversal
feat: cache report totals
feat: add report explain command
feat: add rate limiting and structured logging
ci: add continuous integration pipeline
fix: generate next route types before the typecheck <- finding: CI caught what
                                                      passed locally

F — quality
test: add end to end frontend tests
fix: make payment and reversal work outside localhost <- finding: the E2E caught
                                                        crypto.randomUUID
chore: apply security review
docs: update project documentation
```

**Blocks G and H — remaining.**

```
G — a timed start from scratch
perf: build report indexes after bulk seed
docs: document clean install timing

H — empty the pending list (docs/producao.md)
perf: size the innodb buffer pool
perf: add fulltext index for billing description
perf: export csv from raw rows
feat: replace pdf renderer with incremental writer
feat: add asynchronous export for large reports
perf: evaluate partitioning billings by date
feat: add read replica for report queries
docs: empty the pending list
```

Block H closes `docs/producao.md`. A pending item dies in one of two ways, and both
count: implemented, or measured and discarded with the number that justified
discarding it. What does not count is staying listed as an intention.

---

## How to work in this repository

- One stage at a time, in the order above. Do not run ahead of the stages and do
  not chain modules together.
- When a stage is finished, stop and present the diff before moving on.
- No technical decision lives only in the code: if a reasonable alternative exists,
  the choice and the reason go into the documentation. The README is the front door
  and stays short — the detail lives in `docs/`, by subject. A new decision goes
  into its subject's document, and only moves up to the README if it changes what a
  reader needs to know in the first three minutes.
- Do not build anything beyond what the brief asks for. Extra scope widens the
  surface for mistakes. In stage 2, "what the brief asks for" includes the brief's
  list of extras — and nothing outside the commit at hand.
- Do not swap a library or a pattern without recording the decision in `docs/`.
- **Measure against the real base, not against the suite.** The PDF cap passed
  every test with a value an order of magnitude above what was possible, because
  tests use few rows. A decision about volume, limits or performance requires
  measurement outside the suite, with the 2 million records loaded.
- **What passes on this machine can fail on a clean clone.** `make lint` passed
  with route types the development server had generated and that do not exist in a
  fresh checkout. CI is what caught it. A check that depends on a generated artefact
  has to generate it.
- **Before theorising, read the container's log.** Hydration was not completing in
  the end-to-end tests, and Next's log named the missing option. Two runs were lost
  to not having read it.
- **A tool that scans `vendor/` measures the internet, not the project.** The
  vulnerability scanner reported 23 critical findings, all of them in third-party
  minified code. A tool's report goes into the documentation with an explicit
  statement of what was scanned.
- **Secure context in the browser.** `crypto.randomUUID()` and the `crypto.subtle`
  family only exist over HTTPS or on `localhost`. Client code that depends on them
  breaks on any other host served over HTTP — and it breaks silently, inside the
  handler.
- **`--no-cache` is not "from scratch".** `docker compose build --no-cache` ignores
  the layer cache, but it does not re-download the base image already in BuildKit's
  cache. A clean-install measurement has to time that download separately, or the
  number comes out optimistic with no warning at all.
- **A measured number has copies.** When a measurement changes, look for the old
  number across the whole repository. The 2 million load dropped from 51 to 46
  minutes in the README and in `docs/performance.md`, and `docs/producao.md` went on
  saying 51 for a whole commit.
- **A tweak that speeds up reads can slow down writes.** The 1 GB buffer pool made
  the report's queries 1.5 to 2 times faster and the 2 million load 34% slower on
  this machine. Having measured the gain on one side, measure the other — and when
  the strange number shows up, run a control on the old configuration before blaming
  the machine or the tweak.
