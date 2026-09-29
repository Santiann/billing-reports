# Architecture and decisions

[← README](../README.md)

- [Services](#services)
- [Authentication](#authentication)
- [Modelling](#modelling)
- [Interest calculation](#interest-calculation)
- [Technical decisions](#technical-decisions)

## Services

```
docker compose ps
```

| Service | Image / build | Host port | Role |
|---|---|---|---|
| `mysql` | `mysql:8.0` | — | The database. Named volume `mysql_data`. |
| `php` | `backend/Dockerfile` | — | PHP-FPM 8.3. Speaks FastCGI on 9000. |
| `backend` | `nginx:1.27-alpine` | **8000** | Serves Laravel over HTTP. |
| `frontend` | `frontend/Dockerfile` (target `dev`) | **3000** | Next.js in development mode. |

### Why nginx is called `backend` and PHP is called `php`

This is the least obvious decision in the file, so it is made explicit.

Inside Compose, Next has two API origins and they are not interchangeable:

| Context | Base | Variable |
|---|---|---|
| Server Components, Route Handlers, middleware | `http://backend` | `API_URL_INTERNAL` |
| Code running in the browser | `http://localhost:8000` | `NEXT_PUBLIC_API_URL` |

The name of the service that answers `API_URL_INTERNAL` has to be the one
**that speaks HTTP**. PHP-FPM does not speak HTTP — it speaks FastCGI on port
9000. If the php-fpm service were called `backend`, every
`fetch('http://backend/...')` from a Server Component would fail, and it would
fail **only inside Docker**, which is this project's worst category of bug.

Calling nginx `backend` keeps `API_URL_INTERNAL=http://backend` literally true.
Whatever does PHP's work is called `php`.

### The backend's automatic bootstrap

`vendor/` and `backend/.env` are gitignored, which means: on a fresh clone
**neither exists**. Left alone, `docker compose up -d` would hand over a broken
Laravel and demand manual steps — exactly what the brief rules out.

`backend/docker/entrypoint.sh` covers that on every start, idempotently:

1. If `vendor/autoload.php` does not exist, it runs `composer install`. Compose's
   bind mount covers the image's `/var/www/html`, so the build's vendor is
   invisible anyway — installing in the entrypoint is what removes the need for
   composer on the host.
2. If `.env` does not exist, it copies from `.env.example`.
3. If `APP_KEY` is empty, it runs `php artisan key:generate`.
4. It ensures `storage/` and `bootstrap/cache/` are owned by `www-data`.
5. It runs `php artisan migrate --force`, with up to 10 attempts.

Step 5 retries because MySQL's healthcheck can pass during the init phase, before
the application's user exists — `depends_on: service_healthy` narrows the window,
it does not close it. And the migration is needed this early: Laravel is
configured with `SESSION_DRIVER=database` and `CACHE_STORE=database`, so without
the tables any web route answers 500.

### File permissions

`backend/Dockerfile` accepts `UID`/`GID` as build args (defaulting to `1000`) and
aligns `www-data` with those values. That is what lets Laravel write into
`storage/` through the bind mount without resorting to `chmod 777`. On Docker
Desktop (macOS/Windows) the value is irrelevant — the mount already translates
ownership. On Linux with a UID other than 1000:

```bash
UID=$(id -u) GID=$(id -g) docker compose up -d --build
```

---

## Authentication

Sanctum with a bearer token on the backend; in the browser, **the token never
appears**.

```
browser                Next (server)                Laravel
   |  POST /api/auth/login   |                          |
   |------------------------>|  POST /api/auth/login    |
   |                         |------------------------->|
   |                         |<-- { token, user } ------|
   |<-- { user } ------------|                          |
   |    Set-Cookie: httpOnly |                          |
```

The `POST /api/auth/login` the form calls is a **Next Route Handler**, not
Laravel. It receives the Sanctum token, writes it into an `httpOnly` cookie and
returns only the user. The consequences:

- There is no token in `localStorage`, so an XSS has nothing to steal.
- The browser cannot call Laravel's API directly — it has no credential. What
  attaches the `Authorization: Bearer` is always the server.
- That is why the PDF and CSV exports go through a Route Handler too.

`middleware.ts` protects the routes by the cookie's **presence**. It does not
validate the token: validating is Laravel's job, on every data request. A forged
cookie opens nothing — the API answers 401 and the Server Component redirects.

### The cookie that outlives the token

If the cookie exists but the token is no longer valid (revoked, expired, forged),
the naive path loops: the middleware sees a cookie and sends you to `/`, the
Server Component gets a 401 and sends you to `/login`, the middleware sees the
cookie again and sends you to `/`.

That is why `GET /api/auth/expire` exists: it deletes the cookie and only then
redirects to the login. It sits outside the middleware's `matcher`, so it answers
even with an apparent session. Verified: the chain ends in two hops.

### Response codes

Wrong credentials answer **401**, not 422. The payload is valid; what failed was
authenticating. An unknown email and a wrong password return the **same** message,
so the response does not reveal which emails exist. A malformed payload (a missing
field) is what answers 422, with the errors per field.

Too many attempts answer **429**, with `Retry-After` — see
[login rate limiting](operations.md#login-rate-limiting).

---

## Modelling

### `customers`

| Column | Type | Note |
|---|---|---|
| `name` | varchar | indexed — the listing sorts by name |
| `document` | varchar(14) **unique** | CPF/CNPJ, digits only, unformatted |
| `email` | varchar | |
| `status` | varchar(20) | `active` / `inactive` |

### `billings`

| Column | Type | Note |
|---|---|---|
| `customer_id` | FK **restrict** | a billing is a financial record; deleting a customer does not evaporate history |
| `original_amount` | decimal(12,2) | |
| `monthly_interest_rate` | decimal(6,4) | a fraction: `0.0200` = 2% a month |
| `issue_date` · `due_date` · `payment_date` | date | the three dates that can define the report's period |
| `status` | varchar(20) | `pending` / `paid` |
| `paid_amount` · `paid_interest_amount` | decimal(12,2), nullable | frozen at the moment of payment |

### "Overdue" is not a stored status

The stored enum has two values: `pending` and `paid`. Overdue is a **derivable
condition** — `status = 'pending' AND due_date < ?`, with the reference date
coming down from PHP rather than from `CURDATE()` (the reason is in
[the traps the design had to solve](#three-traps-the-design-had-to-solve)).

Storing "overdue" would require a daily job flipping rows from pending to overdue.
Between two runs of that job the column would be lying, and in a financial report
that is worse than the cost of deriving. The derivation is always correct, runs in
SQL and is indexable by the pair `(status, due_date)`.

### Why DECIMAL and not FLOAT

Money in floating point accumulates rounding error. The report sums interest over
the entire filtered set — millions of rows — and the error grows with the number
of terms summed. Eloquent's casts are `decimal`, which returns a **string**, not a
float: that is intentional, and there is a test asserting `1234.56` comes back
from the database as `'1234.56'`.

### The freezing columns

`paid_amount` and `paid_interest_amount` are written at the moment of payment and
never recomputed. Without them, a billing paid late would change value with every
day that passed, because the interest calculation is a function of the current
date.

---

## Interest calculation

**Compound** interest:

```
updated_amount = original_amount x (1 + monthly_rate) ^ (days_late / 30)
```

Only what is **overdue and unpaid** accrues. A billing within term has zero
interest; a paid billing reads the frozen values.

### The rule has one source, with two faces

`App\Domain\Billing\InterestCalculator` exists because the report needs to **sort
by updated value** and **sum interest over the entire filtered set**. If the
calculation lived only in PHP, either of those operations would force loading the
whole result into memory.

| Face | Where it is used |
|---|---|
| `updatedAmountSql()` / `interestAmountSql()` | `selectRaw` in the listing and in the aggregations |
| `for(Billing)` | displaying a single billing |

Two implementations of the same rule drift apart in silence. That is why
`InterestCalculatorTest` runs **the same matrix of 12 cases through both faces**
and asserts equality down to the cent — within term, overdue by 1, 30, 281 and
400 days, zero rate, high rate, broken cents, paid within term and paid late.

### Three traps the design had to solve

**`travelTo()` does not move MySQL's clock.** If the SQL face used `CURDATE()`,
the consistency test would compare PHP on frozen time against SQL on real time
and would never close. The reference date comes down from PHP as a literal —
generated from a Carbon, never taken from the request. It is also what makes it
possible to compute interest *at the payment date*, which is what freezing
requires.

**Division in MySQL returns a DECIMAL, not a double.** `400 / 30` becomes
`13.3333`, truncated to four places, whereas in PHP it is `13.333333…`. Different
exponents, a different `POW`, divergent faces.

That is not theoretical: a sweep of 900 days x 6 rates x 3 amounts found **78
combinations** in which the truncation changes the cent. One of them is in the
test's matrix — R$ 987,654.31 at 3.5% with 281 days late, where DECIMAL gives
`1363158.13` and double gives `1363158.14`. The `/ 30e0` in `compoundSql()` forces
the division to become a double, and removing it makes that case fail.

**PHP and MySQL break the half-cent tie in opposite directions.** When the
arithmetic lands exactly on the half cent, PHP's `round()` rounds half away from
zero and MySQL's `ROUND()` over a `DOUBLE` rounds half to even:

```
4224.10 at 5% a month, 30 days late  ->  4435.305
PHP   round(, 2)   4435.31     half away from zero
MySQL ROUND(, 2)   4435.30     half to even, because the argument is a DOUBLE
```

On a paid billing this never shows — both faces read the frozen column. On a
**pending overdue** billing it does: the detail screen uses the PHP face and the
report uses the SQL face, and the two would show different values for the same
billing. That is exactly the screen-against-report inconsistency the brief rules
out.

The frequency is low and was measured, not estimated: **one occurrence in
200,000** combinations swept, and one in the base of 2,000,000 (13,654 paid late).
It only happens when the product is exact enough to land on the tie, which in
practice means lateness that is a multiple of 30 days.

The fix is **six guard digits**: round to six places first and only then to two.
On the PHP face, `round(round($v, 6), 2)`; on the SQL face, a `CAST` to
`DECIMAL(20,6)` before the `ROUND`. On both engines the final rounding then
operates on an exact decimal rather than on the double, and the tie breaks the
same way. The sweep of 200,000 combinations that used to find one divergence now
finds zero.

The secondary gain justifies it on its own: the two `pow()` calls run in different
containers and do not share the same libm, so a 1 ULP difference between them is
possible. The guard digits absorb that.

### Freezing at payment

`App\Domain\Billing\RegisterPayment` computes the interest **at the payment
date**, not at today: a backdated payment produces that day's value. From then on
the billing stops accruing — `InterestCalculator` returns the stored columns
instead of recomputing, on both faces.

The amount actually received may differ from the computed one (a settlement, a
discount); when not supplied, the updated value is assumed. The computed interest
stays recorded either way.

The factory uses **the same service** in its `paid()` and `paidLate()` states.
Writing the frozen values by hand in the factory would make it a second
implementation of the rule, and the tests would start validating the copy instead
of the original.

---

## Technical decisions

**A comment answers WHY, never WHAT.** The code says what it does; a reader can
read it. What cannot be recovered by reading is the alternative that was
discarded, the number that decided a limit, or the trap that has already cost an
afternoon. That is why the comments in this repository are long where the decision
was hard — `InterestCalculator`, the index migration, the PDF cap — and absent
where the code is obvious.

A sweep over every comment in the backend, the frontend and the tests removed the
Laravel skeleton's boilerplate, which repeated the method's signature:

```php
/**
 * Run the migrations.          <- the method is called up()
 */
/**
 * Define the model's default state.    <- the method is called definition()
 */
```

Out went the empty-bodied `//` lines too, and a commented-out `use` the skeleton
leaves in `User`. Fifty-one lines, not one of them carrying information.

One comment was not removed but **corrected**, and it was worth more than all the
others put together: the `billings` migration said "overdue" is derived with
`due_date < CURDATE()`. That is precisely the function this project's
architecture rules out — the reference date comes down from PHP, otherwise the
consistency test never closes. A wrong comment is worse than a verbose one: the
verbose one gets ignored, the wrong one gets believed.

**nginx in front of PHP-FPM, rather than `artisan serve`.** Laravel's built-in
server is single-threaded and represents nothing of real behaviour under load.
Since the project has an explicit streaming-export requirement, `default.conf`
turns `fastcgi_buffering` off — with buffering on, nginx would hold the whole CSV
before sending the first line, undoing the `StreamedResponse`.

**A multi-stage frontend Dockerfile, with Compose using the `dev` target.** The
four stages are `deps` (npm ci), `dev` (HMR, used by Compose), `build` (produces
the bundle) and `runner` (the production image). `runner` depends on
`output: "standalone"` in `next.config.ts`, which emits a `server.js` with only
the dependencies actually used. The production target exists and builds, but it is
not what Compose starts.

**`node_modules` and `.next` in anonymous volumes.** The `./frontend:/app` bind
mount would hide the container's copies, and the native binaries (`@next/swc`,
`lightningcss`) compiled for the host are not Alpine's.

**MySQL's port is not published.** Nothing in the acceptance criteria needs it,
and publishing it is the easiest way to collide with a MySQL already running on
the host. To inspect the database:

```bash
docker compose exec mysql mysql -u billing -psecret billing
```

**Tailwind CSS on the frontend.** It came with `create-next-app`'s default
scaffold. The interface does not need advanced design, but it does need to be
responsive and componentised, and the utility layer solves that without
introducing a component library nobody asked for.

**Login rate limiting lives in the domain, not in the `throttle` middleware.**
There are two counts per minute — five wrong attempts on the same account from the
same IP, and twenty from the same IP across every account — because they are two
different attacks, and a single count would let one of them through. It is
`App\Domain\Auth\LoginThrottle` rather than the framework's middleware because the
controller needs three operations, not one: ask, count, and CLEAR on a successful
login. Clearing requires the same key, and the one the middleware derives
internally is a framework implementation detail. The whole thing, including why
the per-credential limit includes the IP, is in
[login rate limiting](operations.md#login-rate-limiting).

**`UserResource` rather than returning the model.** It fixes the response's shape
from the start and stops a new column on the table from leaking into the API
without someone deciding. The same pattern customers and billings follow.

**Credentials in plain text in `docker-compose.yml` and `.env.example`.** This is
a local development environment, and the acceptance criteria require that starting
it does not depend on filling in any secret. The `mysql` service's values mirror
those in `backend/.env.example`; changing one requires changing the other.
