# Performance: measurements and indexes

[← README](../README.md)

- [Dashboard](#dashboard)
- [Indexes](#indexes)
- [InnoDB's buffer pool](#innodbs-buffer-pool)
- [Totals cache](#totals-cache)
- [The execution plan as a tool](#the-execution-plan-as-a-tool)
- [CSV export](#csv-export)
- [PDF export](#pdf-export)
- [Generating volume for testing](#generating-volume-for-testing)

## Dashboard

The home screen shows the current month's indicators and the last twelve months'
series. One call, two aggregation queries, **not a single row loaded for PHP to
sum** — over two million billings that would not be slow, it would be impossible.

Measured against the full base:

| | |
|---|---|
| `GET /api/dashboard` | 0.66s – 0.93s |
| **The full page, with Next's SSR** | **0.84s – 1.42s** |

The criterion was 3 seconds. The first version spent 2.1s to 3.1s on the page, and
three measurements changed the design on the way here.

### 1. A `GROUP BY` over a year costs 5x more than twelve one-month ranges

The obvious form of the series is to group the whole year by month:

```sql
SELECT DATE_FORMAT(due_date, '%Y-%m'), SUM(original_amount) ...
WHERE due_date BETWEEN ? AND ? GROUP BY 1          -- 1.75s
```

The `EXPLAIN` explains it: the function over the column stops MySQL from grouping
in index order, and it builds a temporary table with the year's 666,000 rows
(`Using temporary`). Without the covering index it does not even try — it picks a
full scan of the 2,000,000, because seeking a third of the table costs more than
reading all of it.

Twelve narrow ranges joined by `UNION ALL` — one per month — are twelve simple
ranges the index answers without a temporary table:

```sql
SELECT ... WHERE due_date >= '2026-09-01' AND due_date < '2026-10-01'
UNION ALL ...                                      -- 0.33s
```

### 2. The covering index is worth 25x, and all five columns are used

`billings_dashboard_index` is `(due_date, status, monthly_interest_rate,
original_amount, paid_amount)`. The report's seven indexes point at the row; this
one **carries the values inside itself**, and the `EXPLAIN` comes out with
`Using index`.

| Query | Without covering | With this index |
|---|---|---|
| The 12-month series | 8.25s | **0.31s** |
| The month's indicators | 0.72s | **0.07s** |

A narrow version without `status` and without `monthly_interest_rate` was measured
and discarded: it saves 21 MB and takes the indicators from 0.07s back to 0.72s,
because the interest calculation starts fetching the rate row by row.

The cost is accepted and recorded: 79 MB and an eighth tree to maintain on every
insert, adding to the 4.8x penalty the report's seven indexes already charge the
load.

### 3. The same `POW` was being computed twice per row

`updatedAmountSql()` and `interestAmountSql()` both carry the compound interest
calculation. Summed side by side, MySQL ran the `POW` twice on each of the month's
55,000 rows.

The updated value is now computed once in a subquery, and the interest comes out of
it by subtraction — which only holds because the sum is over **pending**, and on a
pending billing interest is exactly updated value minus original. It would not hold
on a paid billing, which is why a paid one enters as zero.

**0.87s → 0.25s**, with all six numbers identical to before.

### The charts

Two of them, from the same twelve numbers — the second costs no query at all:

- **Billed and received per month**, a stacked column. The question is part-to-whole
  over time: the full height is the month's billed amount and the cut shows how much
  of it turned into money. Two bars side by side would answer "which is bigger",
  which is not the question.
- **Collection rate**, a line. The same numbers, a different reading: collection
  efficiency is what you cannot see when billing and received grow together. A fixed
  0 to 100% axis, because stretching it to the data's range would turn a two-point
  swing into a mountain.

They are SVGs assembled on the server, **with no JavaScript and no charting
library**. A chart of twelve numbers is a figure, not an application: highlighting
the column under the cursor is CSS, and the exact value lives in the `<title>` and
in the table right below, folded into a `<details>`.

**The series' palette was validated by script, not by eye.** The green and the amber
the badges use were rejected as a chart palette:

```
#1c6448 / #8a5d12   ΔE 14.6 normal · 6.7 protan   → REJECTED
#147a58 / #c47d0c   ΔE 22.4 normal · 10.5 protan  → approved (light)
#2d9d76 / #b07d20   ΔE 16.4 normal ·  9.9 deutan  → approved (dark)
```

A badge comes with text beside it and survives nearby colours; a chart's fill has no
text and has to distinguish itself on its own. The dark theme's steps are not the
light ones lightened — the acceptable luminosity band is a different one (L 0.48–0.67
against 0.43–0.77).

On a narrow screen the charts **scroll horizontally** rather than shrink: the SVG
would scale the labels along with everything else, and 11px text would become 5px at
360px.

---

## Indexes

Seven indexes, each with the query it serves. There is one principle: **the equality
column before the range column**. MySQL walks a composite index left to right and
stops using it at the first range column — everything after it becomes a post-read
filter, not a seek.

| Index | The query it serves |
|---|---|
| `(issue_date)` | `WHERE issue_date BETWEEN ? AND ?` |
| `(due_date)` | `WHERE due_date BETWEEN ? AND ?` |
| `(payment_date)` | `WHERE payment_date BETWEEN ? AND ?` |
| `(customer_id, issue_date)` | `WHERE customer_id = ? AND issue_date BETWEEN ? AND ?` |
| `(customer_id, due_date)` | `WHERE customer_id = ? AND due_date BETWEEN ? AND ?` |
| `(customer_id, payment_date)` | `WHERE customer_id = ? AND payment_date BETWEEN ? AND ?` |
| `(status, due_date)` | `WHERE status = ? AND due_date BETWEEN ? AND ?` and the "overdue" filter |

The three dates need separate indexes because the user chooses which one defines the
period, and MySQL will not use a `due_date` index to filter `issue_date`. The
variants with `customer_id` in front exist because the foreign key on its own finds
the customer's rows and then tests the date row by row; with the pair, the date
becomes a seek too.

`ReportIndexTest` asserts all seven exist **with the columns in the right order** and
that they are applicable to the queries. Without that test, removing an index would
degrade the report in silence.

### Execution plans, before and after

The plans below were collected by hand, pasting queries into the MySQL client. They
can now be reproduced with a command —
[`report:explain`](#the-execution-plan-as-a-tool) — which takes the queries from the
same path the API uses.

| Query | Before | After |
|---|---|---|
| period by due date | `ALL` · no key · **1,989,965 rows** | `range` · `due_date_index` · **107,694** |
| customer + period | `ref` · FK · 418 | `range` · `customer_due_date_index` · **15** |
| overdue | `ALL` | `range` · `status_due_date_index` · 994,525 |

### The report's response time

| Scope | Before | After | |
|---|---|---|---|
| 1 month + customer | — | **0.24s** | |
| 1 month + overdue | 4.21s | **1.09s** | −74% |
| 1 month, sorted by updated value | 3.46s | **1.84s** | −47% |
| 1 month | 3.80s | **2.2s** | −42% |
| **1 year** | **4.34s** | **6.2s** | **+43%** |

### The one-year scope got worse, and that is expected

It is not a regression to hide: it is the selectivity threshold.

A range index is read in order and, for each entry, makes a random access to the
primary key to fetch the rest of the row. That pays off while the scope is small. The
one-year period has **518,170 rows, 26% of the table** — above the threshold, and half
a million random accesses cost more than a sequential read of the whole table.

Measured in isolation, without the API's noise:

| Aggregation | With the index | Without it (`IGNORE INDEX`) |
|---|---|---|
| 1 month (5% of the table) | **0.96s** | 1.47s |
| 1 year (26% of the table) | 2.43s | **2.32s** |

The other factor is that the totals are inherently O(n): summing interest requires
computing `POW` for every row in the filtered set. No index avoids that — an index
finds the rows, it does not remove the arithmetic.

Of the production mitigations listed earlier, the per-filter-combination totals cache
was built, [with measurements and an invalidation strategy](#totals-cache). An
aggregates table updated by events and partitioning by date remain the alternatives
for when the cache is not enough — and it is not enough for the first query of each
scope.

### Cost on disk

| | Before | After |
|---|---|---|
| Data | 107 MB | 177 MB |
| Indexes | 43 MB | **322 MB** |

The indexes came to weigh almost twice the data. With `innodb_buffer_pool_size` at
its default of 128 MB, none of that fitted in memory. The pool was sized later, with
measurements — see [InnoDB's buffer pool](#innodbs-buffer-pool).

The migration took **9min38s** to build the seven indexes over two million rows. In a
clean environment it runs over an empty table and is instant; the cost shows up
afterwards, in the seeder, which then maintains seven indexes on every insert.

### The rollback had a defect

The index the foreign key used was created automatically by InnoDB. When the
composites with `customer_id` on the left appeared, **InnoDB discarded it as
redundant** and started supporting the constraint with one of them.

The consequence: dropping the composites in `down()` failed with

```
SQLSTATE[HY000] 1553 Cannot drop index
'billings_customer_payment_date_index': needed in a foreign key constraint
```

`down()` recreates the `customer_id` index **before** removing the composites.
Verified by running the full cycle on a throwaway database: after the rollback
exactly `PRIMARY` and `billings_customer_id_foreign` remain, the pre-migration
state.

---

## InnoDB's buffer pool

```yaml
--innodb-buffer-pool-size=1G          # docker-compose.yml, mysql service
```

The default of 128 MB does not hold the billings table: **177 MB of data plus around
400 MB of indexes**. At that size a one-year aggregation re-read **114,000 pages from
disk on every run**; from 512 MB upwards, none.

The read gain costs writes, and both sides are on record:

| | Effect of the 1 GB pool on this machine |
|---|---|
| The report's queries | 1.5 to 2 times faster |
| Loading the 2,000,000 | **34% slower** |

That second number is the one worth keeping. A tweak made to speed reads up slowed
the bulk load down, and the difference was only attributed to the pool after a
control run on the old configuration — the strange number came first, and blaming the
machine or the tweak before measuring would have been the easy mistake.

The load measurements in
[indexes deferred during the load](#indexes-deferred-during-the-load) were taken
**before** this change, on the 128 MB pool, and are labelled as such there.

---
## Totals cache

The totals are the report's expensive part: summing interest requires computing
`POW` for every row in the filtered set, and no index removes the arithmetic. From
here on they are cached per scope — and most of the work was not making the cache
right, it was guaranteeing it **never serves a stale number**.

### Before: 12 seconds, not 6

The earlier measurement recorded 6.2 s for the one-year scope. Measured again before
this change, on the same scope (due in 2026, 519,986 billings), with the machine
idle: **12.1 to 14.0 s**. Query by query, from the query log:

| Query | Time |
|---|---|
| the totals aggregation | **8.2 – 8.5 s** |
| the page of 25 rows | 2.3 – 2.7 s |
| the pagination `COUNT` | 0.35 s |
| the page's customers | 1 – 2 ms |

Part of the growth has an author and a number: the
[guard digits](arquitetura.md#three-traps-the-design-had-to-solve) that made the two
faces agree on the half cent. An A/B of the aggregation straight in MySQL, the same
query with and without the `CAST(... AS DECIMAL(20, 6))`:

| Expression | Time | Updated sum |
|---|---|---|
| with the `CAST` (the current one) | 4.33 – 4.54 s | 2,832,396,064.22 |
| without the `CAST` | 2.84 – 3.17 s | 2,832,396,063.9201 |

The `CAST` costs some 40% of the aggregation, and it stays: the sums differ in
exactly the cents it exists to get right. The rest of the distance — 4.4 s in raw SQL
against 8.3 s through the report's class — is not explained here. The visible
difference is that the class sends the dates as bound parameters and the raw SQL sent
them as literals, which can change the plan. That is exactly what the `EXPLAIN`
command exists to show.

### After

| Call, one-year scope | Time |
|---|---|
| the first, uncached | 12.9 s |
| subsequent ones, cached | **2.9 – 3.3 s** |
| sorted by updated value, cached | 3.9 s |
| the first after a write | 12.0 s |

With the cache, the aggregation disappears from the query log: in its place come
reading the data version (1.1 ms) and reading the cache (1.7 ms). What is left are
the page's 2.2 s and the `COUNT`'s 0.35 s — the cache does not touch the rows, and
the one-year scope's next bottleneck is the page query.

### What invalidates it

The stored totals hold for three things at once, and any of them changing makes the
next query recompute:

- **The data version.** A counter in a single-row table, which goes up on every write
  to `billings`, inside the write's own transaction. Creating, editing, paying,
  reversing and changing from the console all bump it through the Eloquent observer;
  the import, which writes in batches without going through it, bumps it in each
  batch's own transaction; the volume seeder bumps it when it finishes.
- **The reference date.** Interest changes from one day to the next with no write at
  all, and no event-based invalidation would catch that. The stored date is the same
  one the SQL uses — `InterestCalculator` now exposes it — and not a parallel `now()`
  that could roll over the day between one and the other.
- **The scope.** Period, date basis, customer and status. Sorting, direction and page
  are left out: they change which rows appear and in what order, not the set.
  Reordering the screen reuses the totals, which is the most common use.

Each of those invalidations has a test, and the CSV reuses the totals the screen has
already computed — the PDF does too, by the same route.

### The first idea had a race

The version started out derived from the data, with no write at all: `MAX(id)` of
`billings`, which changes with every billing created, and `MAX(id)` of
`billing_audits`, which changes with every change — and the trail's entry is written
in the change's own transaction. Each cost 1 ms, with no lock and no new table.

It was discarded before it became code, because auto-increment hands out ids in
**allocation** order, not **commit** order. Two simultaneous changes: T1 gets id 100,
T2 gets 101, and T2 commits first. A reader sees `MAX(id) = 101`, computes without
T1's change and stores it. T1 commits — and `MAX(id)` is still 101. The total without
T1 would be served until the next write. With a commit costing 0.3 s in this
environment, two simultaneous payments are enough.

### One locked row, and its price

The counter goes up **inside** the write's transaction. The row stays locked until the
commit, two simultaneous writes raise the number in a queue, and the version grows in
commit order — the race above cannot happen. The new data and the new version become
visible at the same instant.

Bumping the counter outside the transaction, after the commit, would remove the queue
and open two windows: the interval between committing the data and committing the
version, in which the cache serves the earlier total, and the process dying between
the two, which leaves the stale total valid until the day rolls over.

The price is the queue: **every write to a billing goes through this row.** For
payments made by people it is imperceptible. For heavy concurrent writing — several
large imports in parallel — it becomes a bottleneck, and then the answer is a
different one: an aggregates table updated by events. The database cache driver's
`increment` was considered and left out: it returns `false` when the key does not
exist, instead of creating it, and does its own `SELECT ... FOR UPDATE` — the same
lock, with more steps and hidden.

The other guarantee is one of order, and it is in the code: the version is read
**before** computing. The stored totals were computed over data at least as new as the
version accompanying them. If a write lands in between, the current version goes up
and the entry is not served; the reverse — stale data under a new version — has no way
to happen.

### One entry per scope, and not one per version

The database cache driver only deletes an expired entry when someone reads it. With
the version inside the key, every write would abandon a row in the cache table
forever. That is why the key is the scope alone, and the **value** holds
`{version, date, totals}`: when the version or the date do not match, the entry is
recomputed and overwritten. The table grows with the number of scopes queried, not
with the number of writes — 306 bytes per scope, measured. The one-day expiry only
exists for the scope nobody queries again.

The driver is the database one, the project's default. Redis would read faster, but it
is a fifth service outside the brief's fixed stack — and swapping the driver later does
not touch correctness, which comes from the version table, not from the cache. An
uncached query pays, on top of the aggregation, for writing the entry: one more commit.

---

## The execution plan as a tool

```bash
docker compose exec php php artisan report:explain --start=2026-01-01 --end=2026-12-31
make explain ARGS="--start=2026-01-01 --end=2026-12-31 --analyze"
```

The earlier index measurements were made by pasting queries into the MySQL client. The
effort was not the problem: the problem is that **a hand-pasted query ages without
warning**. It goes on explaining, perfectly well, SQL the code no longer generates —
and the `EXPLAIN` of a query that no longer exists is worse than none, because it looks
like information.

The command has **no SQL written inside it.** It runs the same path the API uses,
listens to what Eloquent sent to the database and explains every captured query. If the
report changes, the command changes with it. That is how the `pagination count` got
onto the list: nobody wrote it, it comes out of `paginate()`, and it is the only one of
the four that was not documented.

Two choices the command makes on purpose:

- **It does not go through the totals cache.** It calls the aggregation directly,
  because the cache is precisely what the tool must not see — otherwise the report's
  most expensive query would vanish from the tool built to look at it.
- **It refuses an invalid option loudly.** The filters object discards a value outside
  the allowlist and falls back to the default, which is the right protection for the
  API because those values become column names in SQL. In a diagnostic, silently
  falling back would have someone measure a scope that is not the one they asked for
  and conclude the wrong thing.

`--analyze` swaps `EXPLAIN` for `EXPLAIN ANALYZE`: MySQL executes and returns the
actual time of each operation. `--literals` explains the same SQL twice, with bound
parameters and with the values inlined.

### What the first run found

On the one-year scope of the 2,000,000 base, the report's four queries:

| Query | `type` | Key | Estimated rows | Extra |
|---|---|---|---|---|
| Pagination count | `range` | `billings_status_due_date_index` | 221,013 | `Using index for skip scan` |
| The report's page | **`ALL`** | **none** | **1,989,515** | **`Using where; Using filesort`** |
| The page's customers | — | (PK, 25 ids) | 25 | — |
| Totals | `range` | `billings_due_date_index` | 994,757 | `Using index condition; Using MRR` |

**The previous hypothesis fell.** The cache measurement left a question open: the
application sends the dates as bound parameters and the hand measurement sent them as
literals, which could change the plan. With `--literals`, the plans are **identical**
across all three queries over `billings` — same key, same estimated rows, same `Extra`.
The time difference between the two measurements does not come from there; it comes
from the buffer pool's state and from contention on the machine, which in this
environment moves the aggregation's time from 4 s to 27 s with the suite running
alongside.

**And something else showed up, which was not being looked for:** the page query does a
**full scan with a filesort**, even with `billings_due_date_index` among the candidates.
That is what explains the 2.2 to 3.2 s left over after the totals cache — the one-year
scope is 26% of the table, the `SELECT` asks for the whole row, and the optimiser
concludes scanning is cheaper than 520 thousand random accesses to the primary key; then
it sorts half a million rows in a filesort to return 25. A finding recorded, not fixed
here: the fix is an index, and an index has a measurement of its own.

### `--analyze`: where the time goes, operation by operation

With the machine idle, on the same one-year scope. It reads from the inside out — the
innermost operation happens first:

```
── The report's page · executed in 2,416 ms
-> Limit: 25 row(s)                                    (actual time=2811..2811 rows=25)
    -> Sort: due_date DESC, id, limit input to 25       (actual time=2811..2811 rows=25)
        -> Filter: due_date between 01/01 and 31/12     (actual time=0.174..2576 rows=519986)
            -> Table scan on billings                   (actual time=0.169..2273 rows=2e+6)
```

Two million rows read to deliver 25: the scan alone costs 2,273 ms, the filter leaves
519,986 and the sort is over that half million.

```
── Totals · executed in 8,583 ms
-> Aggregate: sum(...), count(0)                       (actual time=9262..9262 rows=1)
    -> Index range scan using billings_due_date_index   (actual time=28..5776 rows=519986)
```

Here the index is used: 5,776 ms to walk the scope's 519,986 rows, and the rest up to
9,262 ms is the arithmetic — `POW` and `CAST` per row, which no index removes.

```
── Pagination count · executed in 362 ms
-> Aggregate: count(0)                                 (actual time=478..478 rows=1)
    -> Covering index skip scan on billings            (actual time=0.121..334 rows=519986)
```

Two readings on that last one. The first is that 519,986 rows in 334 ms show what a
covering index does: no trips back to the table. The second is that the optimiser's
estimate for that path was **221,013 rows against 519,986 real ones** — wrong by 2.4x,
and still the chosen path was the cheapest of the three.

A warning about `--analyze`'s numbers: the instrumentation charges. The same queries
measured without it gave 362, 2,416 and 8,583 ms, against 478, 2,811 and 9,262 ms with
it. It is there to show the shape and the proportion, not to pin down absolute time.

### The pagination count uses an index nobody asked for

`billings_status_due_date_index` exists for the overdue filter. The count filters no
status at all, and MySQL uses it anyway, in a **skip scan**: it walks the index once per
distinct value of the first column — `pending` and `paid` — and inside each one takes
advantage of `due_date`'s range. Two passes over a narrow index cost less than one over
the wide `due_date` index, and the row estimate drops from 994 thousand to 221 thousand.

---
## CSV export

`GET /api/reports/billings/csv`, and on the frontend the **Exportar CSV** button on the
report screen.

The file carries, in this order: the selected period and the applied filters at the top,
the column header, the rows, and the totals in the footer.

### Streaming, and the proof that it is streaming

`lazy()` walking the result in blocks of a thousand, writing row by row into
`php://output` inside a `StreamedResponse`. The set never exists whole in memory.

Claiming that is easy; the measurement against the two-million base:

| | |
|---|---|
| Scope | 1 month — 56,680 billings |
| **Time to first byte** | **0.88s** |
| Total time | 55.6s |
| File | 4.96 MB, 56,692 rows |

The first byte leaves in under a second while the whole file takes nearly a minute. In
an implementation that assembled the set before responding, the two numbers would be
equal — that distance is what proves the streaming.

Memory confirms it. Sampled every 12 seconds during a three-month export (~170 thousand
rows):

```
before   70.9 MB
t+12s    80.3 MB      t+48s    80.3 MB
t+24s    80.7 MB      t+60s    80.1 MB
t+36s    80.3 MB      t+72s    80.6 MB
```

Flat. Accumulating into an array would show the curve climbing to the end.

### Where the time goes

It is not the internal pagination's `OFFSET` — measured, it costs the same at any depth,
because the period index already narrows the set:

| | |
|---|---|
| `LIMIT 1000 OFFSET 0` | 0.34s |
| `LIMIT 1000 OFFSET 55000` | 0.31s |

The 57 blocks add up to about 18s of database time. The rest is PHP: hydrating 56
thousand Eloquent models and instantiating Carbon for each date. That comes to about a
thousand rows a second.

The production optimisation would be reading raw rows with `DB::table()` and a join
instead of models — trading the domain's convenience (the status enum, `isOverdue()`)
for speed. It was not done here because the requirement is not to blow the memory, and
that is met and measured.

### The file's format

A **semicolon** delimiter and decimals with a comma, plus a UTF-8 BOM. Whoever opens a
billing report opens it in Excel in Portuguese, where the comma is the decimal separator
and the semicolon is the expected delimiter. Without the BOM, Excel reads UTF-8 as
Latin-1 and the accents turn to garbage.

It is a choice made for the recipient, not for the parser: for programmatic consumption,
standard comma-separated CSV would be better.

### The download goes through a Route Handler

The browser does not have the token — it lives in an `httpOnly` cookie — so it cannot
call the export endpoint on its own. Next's Route Handler attaches the `Bearer` and
passes the body through.

The body is passed through **unread**: `upstream.body` is a `ReadableStream`, and
consuming it to resend afterwards would hold the whole file in Next's memory, undoing the
backend's streaming. Verified: downloading through Next keeps the first byte at 0.69s
against a total of 2.38s.

---

## PDF export

`GET /api/reports/billings/pdf`, and the **Exportar PDF** button on the report screen.
The same content as the CSV: the period and filters in the header, the rows, and the
totals.

The library: **`barryvdh/laravel-dompdf`**. Pure PHP, no external binary — which avoids
shipping a Chrome inside the container, as the Browsershot-based alternative would
require.

### The PDF has a cap, and the cap came from measurement

Unlike the CSV, there is **no streaming** here, and that is the nature of the format: a
PDF has to be paginated and assembled whole before it exists, because there is no way to
emit page 1 without knowing how many pages there will be.

This project's initial plan called for a cap of 5,000 rows. **It did not survive
measurement.** dompdf's real consumption on this report, with nine columns:

| Rows | Peak memory | Time | PDF produced |
|---|---|---|---|
| 500 | 184 MB | 9.6s | 926 KB |
| 1,000 | 420 MB | 17.9s | 994 KB |
| 2,000 | 1,164 MB | 56.5s | 1,131 KB |
| 3,500 | 2,965 MB | 210.0s | 1,337 KB |
| 5,000 | **blew past 3 GB** | — | — |

The growth is **superlinear**: doubling the rows almost triples the memory. The cause is
structural — dompdf builds a frame tree and a *cellmap* of the whole table before
paginating, so a table of 5,000 rows by 9 columns becomes 45,000 cells as live objects
simultaneously.

The decisions that came out of it:

- **`pdf_max_rows` is 1,000**, not 5,000. It is the largest value that fits
  comfortably.
- **`memory_limit` is 512M** and `max_execution_time` is 120s, in
  `backend/docker/php/app.ini`. The default of 128M brought the generation down at 1,810
  rows, and the 30s default brought it down before the memory even ran out.

Above the cap the response is **422**, with a message that says what to do:

```json
{
  "message": "O relatório tem 1.810 cobranças e o limite do PDF é 1.000. Use a exportação em CSV, que não tem limite.",
  "count": 1810,
  "limit": 1000
}
```

The count comes from the aggregation query, so **not a single row is loaded to discover
there are too many rows** — and the same totals are reused in the document's footer, with
no extra query.

The screen does not let the user discover this by hitting an error: the report reports
`export.pdf_available`, and the button becomes a notice pointing at the CSV when the
scope does not fit.

If PDF at volume were a real requirement, the route would be to swap the renderer for one
that writes page by page — `FPDF` or `TCPDF` emit rows incrementally and do not assemble
the whole tree. It would be uglier and more laborious to style, which is the right trade
when volume demands it.

### Tests

No assertion about the binary: a generated PDF's content is neither stable nor readable,
and testing bytes would be a test that breaks on its own. What gets asserted is the
status, the `Content-Type`, the `%PDF-` signature, and above all the cap's behaviour —
including that it considers the **filtered set** and not the table's size, otherwise the
export would be useless on any real base.

---

## Generating volume for testing

```bash
make seed-volume                            # docker compose exec php php artisan db:seed --class=BillingVolumeSeeder
```

It generates 5,000 customers and **2,000,000 billings**, with issue dates spread over
three years so the period filter has something to narrow. For a smaller sample:

```bash
docker compose exec -e BILLING_SEED_COUNT=100000 php     php artisan db:seed --class=BillingVolumeSeeder
```

It deliberately does not run from `DatabaseSeeder` — it is minutes of execution, and not
what you want on every `db:seed`.

**Batch inserts, not a factory record by record.** The factory instantiates a model,
fires events and issues one INSERT per row; over two million billings the difference is
not a percentage, it is an order of magnitude. The seeder builds raw arrays and inserts
in blocks of 2,000, with the query log turned off — without that Laravel accumulates
every INSERT in memory and the process dies before the end.

The seeder **truncates the tables before starting**. The customers' documents are
sequential to guarantee uniqueness without querying the database, which would make a
second run impossible over the first one's data; and measuring queries over volume
accumulated from previous runs would say nothing.

One exception: it **does not truncate an already empty table**. `TRUNCATE` is DDL and
costs ~7s per table on this base even with nothing to delete, and there is a side effect
worse than the time — described in
[Tests](testes.md#the-seeders-test-emits-no-ddl).

### Indexes deferred during the load

An index does not speed an insert up, it slows it down: every row inserted maintains the
table's **eight** secondary index trees. Does dropping them first and recreating them
afterwards pay off? Both strategies were measured end to end, over the same 2,000,000
rows, with the machine idle and the same MySQL configuration — a 128 MB buffer pool, a
100 MB redo log, durable commits. The pool was sized afterwards, and on this machine the
larger pool made the load slower: that re-measurement is in
[InnoDB's buffer pool](#innodbs-buffer-pool).

| Strategy | Total |
|---|---|
| A — load with all 8 indexes present | **310min07s** |
| B — drop, load and recreate | **45min55s** |

**B is 6.8× faster, and it is what the seeder uses.** A's number is recorded because it is
what justifies the choice. B's first measurement, still recreating the indexes in a single
ALTER, gave 51min06s — the difference is just below.

A second run of B, inside the
[clean install](operacao.md#how-long-a-clean-start-takes), gave **49min16s**: 2.9 s to
drop, 32min46s of customers and loading, 16min27s to recreate. That is 7% above the first,
and this one did not have the machine idle from start to finish — it ran alongside light
monitoring queries and a 107 MB download. The range worth quoting is 46 to 49 minutes.

Where B's time goes:

| Phase | Time |
|---|---|
| Truncate and 5,000 customers | 38 s |
| Dropping the 8 indexes | 7.3 s |
| Loading 2,000,000 rows | 29min32s |
| Recreating the 8 indexes, one ALTER per index | 15min38s |

**Why A loses so badly is in the curve.** A's throughput per block of 100 thousand rows:

| Up to | Throughput in the block |
|---|---|
| 500,000 | 421 rows/s |
| 700,000 | 146 rows/s |
| 1,000,000 | 92 rows/s |
| 1,500,000 | 75 rows/s |
| 2,000,000 | **62 rows/s** |

Up to around half a million rows, the indexes fit in the 128 MB buffer pool. From there
on, every insert needs index pages that are no longer in memory, and throughput drops
**7×**. B maintains no secondary index during the load, and its throughput stays **flat**,
between 893 and 1,408 rows/s from the first block to the last. Recreating the eight
indexes in B takes 15min38s — less than a single block of 100 thousand rows at the end of
A, which took 26min50s.

Four concerns the strategy required:

- **`customer_id`'s foreign key has no index of its own.** It leans on the three indexes
  starting with `customer_id`, and dropping all three makes MySQL refuse the `DROP INDEX`.
  During the load there is a temporary index on `customer_id` alone — the same device as
  the index migration's `down()` — and it goes away at the end, **only** if the
  `customer_id` indexes have come back.
- **The indexes come back even if the load fails.** The recreation sits in a `finally`,
  and a test exercises it with a load that throws. The `finally` does not cover a process
  that gets killed; for that case, every run of the seeder starts by recreating whatever is
  missing, right after the truncate, when recreating over an empty table is instant.
- **The index list is a copy of the migrations, and the copy is watched.** A test compares
  the seeder's list against what the migrations create. Without it, a new index forgotten
  in the list would be dropped on the first load and never recreated, with no error at all.
- **Only above 100,000 rows.** On a small load the trade does not pay off, and there is a
  stronger reason: the seeder's test seeds a sample, and DDL inside a test ends
  `RefreshDatabase`'s transaction —
  [the trap that cost 50 seconds per test](testes.md#the-seeders-test-emits-no-ddl). A test
  asserts that a small load emits no DDL at all. The tests that genuinely need DDL live in a
  class of their own, outside `RefreshDatabase`, and only touch the structure of an empty
  table.

**One ALTER per index, and not a single one.** The first implementation recreated the eight
indexes in a single statement, with a comment in the code claiming that came out cheaper.
The claim had no measurement, and the measurement disproved it:

| Recreating the 8 indexes, 2,000,000 rows | Time |
|---|---|
| **one ALTER per index**, table at rest | **10min44s** |
| a single ALTER, table at rest | 19min12s |
| one ALTER per index, right after the load | 15min38s |
| a single ALTER, right after the load | 22min03s |

One by one is **1.8× faster** at rest, and the seeder now does it that way. The four rows
separate the two possible causes. The moment matters: right after the load, with MySQL
still flushing freshly written pages, both strategies cost more than at rest. But the
strategy matters more — at both moments, one by one wins comfortably.

One by one, the dashboard's covering index is the most expensive: 1min59s, against around a
minute for each single-column index.

On the why, only what is verified goes here. The
[MySQL manual](https://dev.mysql.com/doc/refman/8.0/en/online-ddl-memory-management.html)
documents that the DDL buffer — 1 MB by default — is split between the DDL threads, which
are 4 by default: 256 KB each. How that memory divides between several indexes built in the
same statement, it does not document. The obvious explanation — eight sorts contending for
the same buffer — is recorded as a hypothesis, and the implementation follows the number.

### Paid late, with genuinely frozen interest

Forty per cent of the billings are born paid, and **35% of those were paid late** — with
`paid_amount` and `paid_interest_amount` computed, not zeroed.

Without that the measurement base does not exercise the rule that matters most in the
domain: the report would show R$ 0.00 of interest received, and a paid billing's detail
screen would never have frozen interest to display. A base of two million rows in which the
central rule never appears is not a measurement base, it is volume.

**The frozen value comes from `RegisterPayment`**, the same service the API uses when
someone records a payment through the screen. The seeder decides *when* the billing was
paid and nothing else. For that the service gained `freeze()`, which returns the payment's
columns without writing them:

| | who calls it | what it does with the return |
|---|---|---|
| `__invoke()` | the API, the factory | `update()` on the model |
| `freeze()` | the volume seeder | becomes a field on the batch insert's row |

The alternative was writing `amount * POW(1 + rate, days/30)` inside the seeder. It would
be faster and it would be wrong: the measurement base would start validating a copy of the
rule, and a divergence between the two would only surface when someone compared the screen
against the report. `BillingVolumeSeederTest` closes that door — it rebuilds the seeded
billing as pending, pays it through the production service on the same date and demands
equality down to the cent.

Two limits, both with reasons:

- **At most 120 days late.** Without a cap, a billing three years overdue paid at 5% a
  month would accrue `1.05^36` — nearly six times the original amount. It happens, but it is
  not what a billing base looks like.
- **A payment never falls in the future.** The previous version always paid 1 to 25 days
  before the due date, and for a billing still to fall due that produced a payment date after
  today. A billing whose due date is more than 25 days away is simply born pending.

### What freezing costs

An A/B measurement on the same machine and in the same session, 200,000 billings inserted
into the table with the report's seven indexes:

| | total | PHP | INSERT | rows/s |
|---|---|---|---|---|
| Without freezing | 330.1s | 6.5s | 323.5s | 606 |
| With freezing | 364.1s | 32.8s | 331.3s | 549 |

Freezing costs **0.29 ms per paid billing** — 26s more per 200,000 rows, or +10% on the
total. What dominates is the INSERT, at 90% of the time: the seeder is limited by the
database, not by PHP, and that is why swapping `RegisterPayment` for an inline formula would
buy little and would cost the rule's single source.

The cost's detail, measured over 20,000 isolated iterations: `new Billing()` 0.052 ms,
`InterestCalculator::for()` 0.150 ms, and the rest is choosing the date.
