# Operations: running it, watching it, protecting it

[← README](../README.md)

- [Running it](#running-it)
- [API documentation](#api-documentation)
- [Continuous integration](#continuous-integration)
- [Structured logging](#structured-logging)
- [Health check](#health-check)
- [Login rate limiting](#login-rate-limiting)
- [Security review](#security-review)

## Running it

The only prerequisite is **Docker with Compose v2**. You do not need PHP, Node or
MySQL installed.

```bash
git clone https://github.com/Santiann/billing-reports.git
cd billing-reports
make install
```

`make install` is the single command: it starts the four services, **waits for the
entrypoint's migrations to finish** and creates the access user. At the end it
prints the URLs and the credentials.

Without `make` it is two commands — and the second only works once the migrations
have finished, which on the first start [takes a while](#how-long-a-clean-start-takes):

```bash
docker compose up -d
docker compose exec php php artisan db:seed
```

There is no `.env` to copy and no `composer install` to run by hand: the backend's
entrypoint handles both (see
[automatic bootstrap](arquitetura.md#the-backends-automatic-bootstrap)).

| Role | Email | Password |
|---|---|---|
| Administrator | `admin@billing.test` | `password` |
| Read-only | `consulta@billing.test` | `password` |

The second user exists so the read-only role can be seen working (see
[access roles](modulos.md#access-roles)).

### The Makefile's targets

`make` with no argument lists everything. No target hides its `docker compose`: the
right-hand column is what each one runs, for whoever does not have `make`
installed or would rather type it out.

| Target | Equivalent |
|---|---|
| `make install` | `docker compose up -d` + wait + `db:seed` |
| `make up` | `docker compose up -d` |
| `make down` | `docker compose down` |
| `make logs` | `docker compose logs -f` |
| `make shell` | `docker compose exec php sh` |
| `make test` | `docker compose exec php php artisan test` |
| `make coverage` | `docker compose exec php php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text` |
| `make seed` | `docker compose exec php php artisan db:seed` |
| `make seed-volume` | `docker compose exec php php artisan db:seed --class=BillingVolumeSeeder` |
| `make fresh` | `docker compose exec php php artisan migrate:fresh --seed` |
| `make lint` | `pint --test` on the backend, `next typegen`, `tsc --noEmit` and `eslint` on the frontend |
| `make e2e` | `docker compose --profile e2e run --rm e2e` — Playwright against the running stack |
| `make explain` | `docker compose exec php php artisan report:explain` — pass options with `ARGS="--analyze"` |

Two decisions the file records:

**`install` uses `up -d`, not `up -d --build`.** On a clean machine there is no
image and Compose builds anyway, so `--build` adds nothing but one more way to go
wrong: it has to resolve `docker/dockerfile:1` from the registry, which goes
through Docker's credential helper. On WSL with Docker Desktop that helper is an
`.exe`, and when interop is unavailable it fails with `exec format error` — with
the whole stack working. To rebuild deliberately after touching a Dockerfile:
`docker compose up -d --build`.

**`install` waits for the migrations before seeding.** `up -d` hands control back
when the containers start, but php's entrypoint runs the migrations after that.
Seeding without waiting fails with *table users doesn't exist* — and it fails
precisely on the first start, which is the only one where `make install` matters.
Measured here: the wait took 35 seconds with MySQL's datadir already created, and
close to 1min45s on a start from scratch, where the entrypoint still runs
`composer install` before the migrations.

**`lint` needed a `pint.json`.** Pint's `laravel` preset removes the parentheses
from an argument-less `new` — `new InterestCalculator` instead of
`new InterestCalculator()` — and the whole project uses the parenthesised form. Two
ways out were possible: rewrite the code for the preset, or record the choice. I
took the second, because `new X()` is the form PHP 8.4 started accepting chained
(`new X()->method()`) and the one that makes the call look like any other.

The rule cannot simply be switched on, and that is the non-obvious part:
`"new_with_parentheses": true` would also require parentheses on an **anonymous
class**, and would rewrite the four migrations, which use
`return new class extends Migration`. The configuration separates the two cases:

```json
{
    "preset": "laravel",
    "rules": {
        "new_with_parentheses": { "named_class": true, "anonymous_class": false }
    }
}
```

With that, `make lint` passes clean. The other three divergences Pint reported were
real defects and were fixed, not silenced: three test files used a fully qualified
class name mid-code (`\App\Models\Billing::factory()`) instead of a `use` at the
top.

With the four services up:

| | URL |
|---|---|
| API (Laravel, via nginx) | <http://localhost:8000> |
| Application (Next.js) | <http://localhost:3000> |

Checking:

```bash
curl -s -o /dev/null -w 'laravel: %{http_code}\n' http://localhost:8000
curl -s -o /dev/null -w 'next:    %{http_code}\n' http://localhost:3000
docker compose ps
```

Bringing it down while preserving the database:

```bash
docker compose down
```

Bringing it down and deleting the database (the named volume `mysql_data`):

```bash
docker compose down -v
```
### How long a clean start takes

Measured on this machine (WSL2 with Docker Desktop) from a fresh clone of the
branch — no `vendor/`, no `.env`, no `node_modules`, no project image and no
database volume. Each phase was timed separately.

| Phase | Time |
|---|---|
| `git clone --depth 1` | 5 s |
| Downloading the `php`, `node` and `nginx` base images | 25 s |
| Building the `php` and `frontend` images | 4min36s |
| Downloading the `mysql:8.0` image | 51 s |
| `make install` | **7min09s** |
| The first screen: `/login` compiled on demand by Next | 19 s |
| **To the login screen** | **13min25s** |
| `make seed-volume` — 2,000,000 billings | 49min16s |
| **To the measurement base loaded** | **1h02min42s** |

The volume load breaks down into 2.9 s to drop the indexes, around 32 minutes of
inserting and **16min27s** to recreate them. In the measurement from the commit
that deferred the indexes, the same strategy took 45min55s — this run came out 7%
slower, and both are in
[indexes deferred during the load](performance.md#indexes-deferred-during-the-load).

Inside `make install`, by the timestamps in each container's logs:

| Step | Time |
|---|---|
| Network, volume and the four containers created, up to MySQL starting | 1min13s |
| MySQL: creating the datadir | 2min59s |
| MySQL: init scripts — the test database and the application's user | 47 s |
| MySQL: restarting on port 3306 until the healthcheck passes | 16 s |
| php's entrypoint: `composer install`, 119 packages | 36 s |
| php's entrypoint: `.env`, `APP_KEY` and the 12 migrations | 1min13s |
| The last `wait-migrations` probe and the base seeder | 5 s |

Whoever already has the stack and runs `docker compose down -v && make install` in
the same tree skips the build and the downloads, and the entrypoint skips
`composer install` because `vendor/` already exists: by the table, somewhere near
**7 minutes** to the login screen. That number is derived, not measured
separately.

**What the measurement did not cover directly.** The base images did not come from
the cache, and for two different reasons. `php:8.3-fpm-alpine` and `node:22-alpine`
live in BuildKit's cache, and `build --no-cache` ignores the layer cache, not the
base image — it does not re-download it. `nginx:1.27-alpine` is in use by another
project's container on this machine and was not deleted. The 25 s come from a
separate measurement: the three images' compressed layers (107 MB) downloaded
straight from the registry, one after another, without extraction. For comparison,
`mysql:8.0` is 222.8 MB compressed and took 51 s with extraction included.
Downloading is bandwidth: here it varied from 3 to 7 MB/s.

**The phase that varies is MySQL's.** The first measurement of the first-init took
**10min20s** between `Initializing database files` and `ready for connections` on
port 3306. This one took **3min52s** — same machine, same Compose configuration.
The image downloaded now is 8.0.46; the version from the first measurement was not
recorded, and I did not measure the cause of the difference. During that interval
the backend sits waiting for the healthcheck, and that is the correct behaviour.

That is why the healthcheck has a `start_period` of 900s. The first value I tried,
600s, failed by 20 seconds and brought the whole start-up down with
`dependency failed to start: container mysql is unhealthy`.

Failures inside the `start_period` do not consume retries, so the wide window
costs nothing on subsequent boots: with the volume already populated, the
healthcheck passes on the first probe and the stack comes up in seconds.

The probe goes over TCP (`mysqladmin ping -h 127.0.0.1`) on purpose. During the
init MySQL brings up a temporary server with `port: 0`, with no networking — a
probe over the Unix socket would report "ready" while the database still accepts no
connection at all, and the backend would try to migrate against a server without
the application's user.

To follow along: `docker compose logs -f mysql`.

---
## API documentation

The specification lives in
[`backend/resources/openapi.yaml`](backend/resources/openapi.yaml) — **OpenAPI
3.1**, written by hand, covering the 15 endpoints with their parameters, responses,
examples and error codes.

### What stops the spec from rotting

Hand-written API documentation rots in silence: someone adds an endpoint, forgets
the file, and from then on the spec describes a system that no longer exists.
Nobody notices, because nothing breaks.

`OpenApiSpecTest` removes that silence by comparing the spec against Laravel's
router **in both directions**:

| Situation | Result |
|---|---|
| A registered route with no entry in the spec | fails — incomplete documentation |
| An entry in the spec with no registered route | fails — phantom documentation |

The routes deliberately left out — `/up`, `sanctum/csrf-cookie`, the public disk's
file server and the root — are in an explicit list, each with its reason. The list
exists precisely so a new route cannot slip through by omission: if one shows up
that is neither in the spec nor in the list, the test fails and someone has to
decide.

The test demands four more things that separate documentation from an index:

- every operation has a `summary`, `tags` and declared responses;
- every authenticated operation documents the **401** — it is the most likely
  response for someone trying the API for the first time, and the most confusing
  one without an explanation;
- every JSON success response carries an **example**;
- the **PDF cap's 422** is documented, and the example's limit is compared against
  `config/reports.php` — if the cap changes and the spec does not, the test says so.

### The backend's root is the documentation

```bash
curl localhost:8000          # the whole documentation, in HTML
curl localhost:8000/openapi.yaml   # the raw spec, for importing
```

`http://localhost:8000` is no longer Laravel's welcome page. Whoever opens that
address is looking for the API, and serving the framework's welcome page wastes the
one URL the person already knows by heart.

**The page is assembled on the server, and that was the decision that cost the
most.** The easy route was Redoc, Scalar or Stoplight Elements: one line of HTML,
one CDN `<script>` tag, and a good-looking result for free. All three assemble the
page in the browser — `curl localhost:8000` would return `<div id="app">` and
nothing more.

Documentation that only exists after the JavaScript cannot be read from a terminal,
cannot be indexed, does not open without internet and does not survive a CDN going
down. The criterion "`curl` answers with the documentation" is not a whim: it is
what separates documentation from a documentation page.

The cost of the choice is a 200-line controller and a view — resolving `$ref`,
merging the parameters declared at the path level with the operation's, formatting
examples. What it buys:

| | A CDN renderer | This page |
|---|---|---|
| `curl` returns the documentation | no | **yes** |
| Works without internet | no | **yes** |
| A third-party dependency at runtime | yes | **none** |
| JavaScript | required | **zero** |

The look is a printed specification's — paper, ink, a ruled line and section
numbering (`3.6 Records the payment and freezes the interest`). No external font,
for the same reason as no CDN: the page opens offline with the families the machine
already has. There is a print stylesheet, because a document that calls itself a
specification ought to come out well on paper.

The YAML is deliberately not parsed from a cache: it takes a few milliseconds, and
editing the spec and reloading shows the result immediately — which is what you
want from a hand-maintained file.

### Decisions

**YAML and not JSON**, with `symfony/yaml` so the test can read it. JSON would need
no dependency at all, but the spec is a document someone is going to open and read:
YAML accepts comments, and the file opens by explaining why it is verified by a
test. The dependency is small, it is Symfony's and it already lives alongside
Laravel.

**Written by hand and not generated from the code.** An annotation-based generator
(Scramble, L5-Swagger) would produce the spec from the controllers, and it would
never diverge — but it would also never say more than the code already says. The
useful part of this documentation is what the code does not have: why a paid
billing is immutable, why money travels as a string, why the PDF has a cap and the
CSV does not. The test covers divergence; the prose covers the rest.

**Examples taken from real calls.** Every example in the spec came from a real API
response, with the interest values checked against `InterestCalculator` — R$
1,500.00 at 2% a month with 30 days late gives `1500 * 1.02 = 1530.00`. An invented
example is the first thing to go wrong.

The spec was validated with `npx @redocly/cli lint`: **valid**, with two warnings
accepted on purpose — not declaring a licence, and pointing the server at
`localhost`, which in this project is the right server.

---

## Continuous integration

`.github/workflows/ci.yml` runs, on every push, the same checks `make test` and
`make lint` run locally, in **two parallel jobs** — the backend and the frontend do
not depend on each other to be checked, and this way the typecheck does not wait
out the suite's minutes to fail.

| Job | What it runs |
|---|---|
| Backend | MySQL 8 as a service, PHP 8.3 with `pdo_mysql` and `bcmath`, `pint --test`, `php artisan test` |
| Frontend | Node 22, `npm ci`, `next typegen`, `tsc --noEmit`, `eslint` |

The versions and the extensions were not chosen again: they are the Dockerfiles',
and the suite's database is `billing_test` with the credentials `phpunit.xml`
expects. The service's `MYSQL_DATABASE` creates the database on first boot, which
removes the need in CI for the init script Compose uses.

### The job runs on the runner, not inside a container

The [service containers
documentation](https://docs.github.com/en/actions/tutorials/communicating-with-docker-service-containers)
is explicit about the difference, and it decides the design: a job **inside a
container** reaches the service by its label (`mysql`), with no port published; a
job on the **runner** reaches it through `localhost`, and the port has to be
published.

The container route was tempting, because the `mysql` label is exactly the
`DB_HOST` `phpunit.xml` pins — zero extra environment variables. It was left out
because inside a `php:8.3-cli` you would have to compile the extensions and install
Composer by hand, while on the runner `setup-php` delivers all three.

The price is one variable: `DB_HOST: 127.0.0.1`. And it works because of a PHPUnit
detail I **checked before writing the workflow**, rather than assuming:
`phpunit.xml`'s `<env>` does not overwrite an environment variable that already
exists, only with `force="true"`. Running the suite with the variable present, the
connection error named the host — `Host: 127.0.0.1` — proving which one wins.
`phpunit.xml` remains the source of truth for the documented environment,
Compose's.

### CI found a problem on the first push

The first run **failed** — and not in the workflow, in the project. The backend
passed in 56s; the frontend broke with `Cannot find name 'LayoutProps'`.

`LayoutProps` and `PageProps` are types **generated** by Next into `.next/types`,
and `tsconfig.json` includes them. Locally they already existed, created by the
development server — so `make lint` passed. On a clean checkout nobody created
them, and `tsc` cannot find them. The local target had the same hole and nobody
would have noticed until someone cloned the repository and ran the check before
starting the application.

The fix is one step, `next typegen`, which produces only the definitions without
the whole build, and it was applied in **both places** — in CI and in `make lint` —
because the problem belonged to both. It is the pipeline's first concrete return: it
did not serve to confirm what was already known, it served to show what the
development machine was hiding.

The same run brought warnings that `actions/checkout@v4`, `setup-node@v4` and
`cache@v4` run on Node 20, which is deprecated. The current versions were checked
through GitHub's API rather than from memory — `checkout v7`, `setup-node v7`,
`cache v6` — and the workflow moved up to them.

### Three adjustments worth the comment

- **Pint before the suite.** It takes seconds and the suite takes minutes;
  discovering wrong formatting after waiting out the suite is waste.
- **`concurrency` with `cancel-in-progress`.** A new push on the same ref cancels
  the previous run, whose result no longer describes the current code.
- **`permissions: contents: read`.** Nothing here writes to the repository, and a
  token with write access would be surface this workflow does not need.

Coverage stays out of CI: `pcov` instruments the code and the report belongs to
`make coverage`, run when you want to look at it. And the suite runs on MySQL in CI
for the same reason it runs on it in Compose — [on SQLite it would be validating a
different engine](testes.md#the-test-database).

---
## Structured logging

A log line is a JSON object, on `stderr`:

```json
{"message":"login.failed","level_name":"WARNING","context":{
  "user_id":null,"request_id":"5903634cb4dbf38c8aba4a1464858b4f",
  "method":"POST","path":"api/auth/login","ip":"172.20.0.1",
  "email":"descartavel@billing.test"}}
```

`stderr` and not a file because that is where `docker compose logs` looks — and
because the official php-fpm image already turns on `catch_workers_output` and
points `error_log` at descriptor 2, so the line written by the worker reaches the
container's log. Checked before choosing: a file inside the container only serves
whoever is already inside it.

### The identifier crosses the edge

What generates the identifier is **nginx**, with `$request_id`, and the application
receives it, returns it in the response header and repeats it on every log line. If
the client already sent an `X-Request-Id`, it is preserved: whoever correlates calls
across services is whoever sits further up the chain, and overwriting it would break
the link.

nginx's access log prints the same id, and that is what makes the two ends meet:

```
nginx   req_id=caf4414de833cec75d085738f392eab2 rt=0.024
laravel {"message":"login.blocked", …, "request_id":"caf4414de833cec75d085738f392eab2", "retry_after":56}
```

### A processor, and not `Log::withContext()`

The context is assembled by a Monolog processor, evaluated at the moment each line
is written. The obvious alternative was a middleware calling `Log::withContext()`,
and it has a defect that would only have shown up in production: **group middleware
runs before `auth:sanctum`**, so there the user does not exist yet and `user_id`
would come out null — while in the tests, where `actingAs` resolves the user
earlier, it would appear to work. A green test for the wrong reason.

The processor also asks `hasUser()` before `id()`: asking for the id would resolve
the guard from the logger, inverting the order of things. A line written before
authentication — a failed login attempt — comes out with no user, which is the
truth.

And there was a second mistake along the way, caught by the test itself: the guard
around the request fields was `runningInConsole()`. It looks like the right question
and is not — **the suite runs through artisan**, that is, in console, so the tests
would never see the context production sees. The guard became the presence of the
identifier header, which only exists once the middleware has run.

### What goes into the log, and what does not

The log answers "what happened in this request". What happened to the **data** is
the [audit trail](modulos.md#audit-trail), which is a table and not text.

From the login go the three transitions that matter to an investigator:
`login.failed`, `login.blocked` and `login.ok`. The first two carry the **attempted
email** — without it there is no way to tell someone who mistyped their password
from a sweep across accounts, which is exactly the question being asked. It is
personal data in a log, and the trade is recorded here: the benefit is investigating
a mass intrusion attempt, the cost is the email in an operations log.

---

## Health check

```
GET /api/health

{"status":"ok","checks":{"database":{"ok":true,"duration_ms":10.05},
                         "cache":{"ok":true,"duration_ms":6.06}}}
```

Public, because a monitoring probe does not log in. It answers **503** with
`status: degraded` when a dependency fails, naming which one and carrying the
error's message. A health check that always answers 200 is worse than none: the
monitoring starts trusting it and stops warning.

The cache check is a **read**. Writing would prove more and would cost one commit
per probe — with the database driver, every write goes to disk, and monitoring every
ten seconds would write 8,640 times a day to answer a question the read already
answers: the driver is reachable.

Laravel's `/up` still exists and answers a different question — whether PHP came up.
This route answers whether the dependencies answer.

One documentation detail: the spec's linter warns that the operation declares no
4xx. It does not declare one because there is none — the route is public and takes
no input. Inventing a 4xx to silence the warning would be documenting something that
does not exist, so the warning stays.

---

## Login rate limiting

This was the one pending item with an excuse instead of a number: *"choosing a limit
that does not make the suite itself flaky takes care"*. The care is here, and it is
**two counts per minute**, because these are two different attacks and a single
count would let one through:

| Count | Limit | Catches |
|---|---|---|
| email + IP | 5 | brute force against one account |
| IP | 20 | sweeping emails, one attempt at each |

The per-credential limit includes the IP **on purpose**. Counting by email alone
would let anyone lock someone else's account from outside by getting the password
wrong five times: denial of service dressed up as security. The per-IP limit is
loose relative to the other for the opposite reason — an office behind a single IP
has several people logging in legitimately, and what you want to catch there runs
into the dozens.

Three details the tests pin down:

- **A request with neither email nor password does not count.** The limit is checked
  after validation: an incomplete payload is not an authentication attempt, and
  counting it would let a buggy form lock out its own user.
- **A successful login clears the account's count, not the IP's.** Getting the
  password right proves that account is not under brute force; it proves nothing
  about the IP, because whoever is sweeping emails may have hit their own.
- **The 429 says how long is left**, in the body and in the `Retry-After` header.

Measured against the running application: five wrong attempts answer 401, the sixth
answers `429` with `Retry-After: 56`.

### Why not the `throttle` middleware

Laravel's `throttle` solves the common case, and this one has one more piece: a
successful login has to **clear** the count. Clearing requires the same key the
middleware uses, and that key is derived from the limiter's name inside the
framework — depending on it means depending on an implementation detail. The custom
limiter has all three operations (ask, count, clear) in a fifty-line file, and the
Portuguese message comes for free.

### And the suite did not become flaky

Three things guarantee that, and it is worth saying because it was the reason the
item stayed pending: the limit is **per credential**, so a test that gets one user's
password wrong does not disturb the others; the suite's cache is the in-memory one,
so each test starts with a clean count; and no test makes more than two consecutive
attempts on the same account — the ones that test the limit use emails of their own.

---

## Security review

Done with the `vulnerability-scanner` skill, in the order it proposes:
reconnaissance, discovery, analysis, reporting. What follows is the complete result
— what was fixed **and** what was assessed and discarded, with the reason.

### Fixed

| Finding | Why it matters | Fix |
|---|---|---|
| **Open CORS** — the framework's default is `allowed_origins: ['*']` | The API accepts a token in the header; origin `*` is surface this design does not use, because the browser never calls the API directly | `config/cors.php` restricted to the frontend, with explicit lists of accepted and exposed headers |
| **Sanctum token with no expiry** (`expiration => null`) | The session cookie lasts 8h, but the token stayed valid forever — a leak with no expiry date | 480 minutes, the same lifetime as the cookie |
| **No security headers** | Clickjacking, type sniffing, referrer leakage | `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy` on both origins, plus a full CSP on the API |
| **`X-Powered-By: PHP/8.3.33`** | Stating the version saves whoever is probing the work of finding out which CVE to try | `expose_php = Off` |
| **Health leaked the driver's message** | The route is public, and PDO's error names the host, the port and the driver | A generic message in the response, the detail in the structured log |
| **No request cap on the API** | The reason here is not brute force, it is cost: an uncached query over a one-year scope takes 12s of database time, and a loop takes the service down with legitimate credentials | `throttle:api`, 180/min, counted **per user** |
| **`Str::markdown` rendering raw HTML** | A theoretical XSS on the documentation page, whose content comes from the spec | `html_input => escape` |

The API's limit is counted per user, and not per IP, because of a detail of this
design: the frontend calls the API through Next's server, so **every** one of the
application's requests arrives from the same address. Counting by IP would have one
active user limit all the others.

The API's CSP is as restrictive as possible — `script-src 'none'` — and that is only
feasible because the [documentation page](#the-backends-root-is-the-documentation)
is assembled on the server and has not a single `<script>`. The choice not to use an
off-the-shelf spec renderer, made for another reason, paid off here too.

### Assessed and discarded

**A CSP on the Next application.** The development server needs `unsafe-eval` and
inline styles; a policy that only applied in production would go live having never
been exercised, and a CSP nobody tested breaks the application at the worst moment.
What stays are the four headers that hold in both environments.

**Dependencies.** `composer audit` and `npm audit`: no advisories. Both lockfiles
are under version control and CI uses `npm ci`, which installs exactly the lockfile
and fails if it diverges from the manifest.

**SQL injection.** All of the project's raw SQL comes from two places:
`InterestCalculator`, which builds the expression with the reference date coming from
PHP, and the dashboard's aggregations, which use bound parameters. The sort column
and the date basis pass through **two** allowlists — the `FormRequest` and the
filters object — precisely because they become column names.

**CSV upload.** Validated on type and size (20 MB), and the path read is the
temporary file's, the one PHP created, not a value from the request. The reader
streams and fails with a clear message when the header does not match.

**PDF generation.** dompdf ships with `enable_remote` and `enable_php` off: no SSRF
through a remote image and no PHP execution inside the template.

**Access to another user's record.** Any authenticated user sees any billing. There
is no concept of an owning customer nor of an organisation in the brief, and
inventing one would be extra scope; what exists is the
[read-only role](modulos.md#access-roles), which separates reading from writing. It is
recorded as a known limit, not as an oversight.

**`APP_DEBUG=true` and example passwords.** They are the local environment the brief
asks for — `docker compose up -d` has to deliver a usable application, with
documented credentials. In production, `APP_DEBUG=false`, `APP_ENV=production` and
secrets outside the repository are prerequisites, not adjustments.

**Pinning the CI actions by SHA rather than by major.** `actions/checkout@v7` trusts
the tag, which is movable. Pinning by SHA protects against the tag being repointed,
and is the practice for an organisation with high assurance requirements; for this
project the maintenance cost does not pay for itself.

### About the number the scanner reported

The skill's script reported **224 dangerous patterns, 23 critical** — and not one is
ours. It scans the whole directory, and the findings are in
`backend/vendor/phpunit/.../billboard.pkgd.min.js` and friends: string concatenation
in third-party minified code. Run over our code, the result is different:

```
backend/app         0 critical, 0 high
backend/routes      0 critical, 0 high
backend/config      0 critical, 0 high
frontend/app        0 critical, 0 high
frontend/components 0 critical, 0 high
frontend/lib        0 critical, 0 high
```

Recording that matters because reading the report lazily would lead to the opposite
conclusion. A tool that scans `vendor/` measures the internet, not the project — and
its configuration finding, the one about missing headers, was true and became a fix.

### Failing closed

OWASP 2025's last category is exceptional conditions, and it is worth listing what
the project does when something goes wrong:

- **An idempotency key on a server error:** given back, not stored — a 500 is not a
  result, and retrying is the right move.
- **Health with a dependency down:** 503, never an optimistic 200.
- **Logout with the API down:** the cookie is deleted either way. Leaving the user
  stuck in a session they asked to end is worse than an orphaned token, which now
  expires on its own.
- **Without the audit trail, no change:** writing the billing and writing the trail
  are in the same transaction.
