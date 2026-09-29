---
name: laravel-report-tests
description: Conventions and traps for writing this project's backend automated tests (Pest/PHPUnit, Laravel, MySQL). Use it whenever the task involves writing, fixing or reviewing a test — of interest calculation, of the report's filters or totals, of recording a payment, of the CSV or PDF export, or of route and endpoint protection. Use it also when the task is to implement a business rule, because the test comes before the code.
---

# Backend tests

The minimum scenarios are set by the brief and all of them have to exist:

1. An unauthenticated user cannot reach the report
2. An unauthenticated user cannot export reports
3. Interest calculation for an overdue billing
4. A paid billing stops accruing interest
5. The report's filters
6. The report's totals
7. Recording a payment
8. Exporting the report to PDF
9. Exporting the report to CSV

Beyond those, the consistency test described below is mandatory in this project.

---

## Trap 1 — time

The interest calculation depends on the current date. A test that passes today and
breaks tomorrow is not a test.

**Every test that touches interest freezes time with `travelTo()`** and builds its
dates relative to that point. Never use an implicit `now()` when setting up the
scenario, and never use calendar literals.

The paid-billing scenario has to move the clock forward **after** the payment and
assert the value did not change. Without that move the test proves nothing: it
would pass even with the rule wrong.

---

## Trap 2 — a streamed response

The CSV export returns a `StreamedResponse`. `assertSee` and `getContent()` do not
work on it — the body only exists once the callback runs.

Capture it with `$response->streamedContent()` and only then assert about the
content: the header is present, the number of rows, the period and the filters
applied at the top of the file, and the totals in the footer.

Assert too that the export respects the filter: build a set where some of the
records fall outside the filter and verify they do not appear. Counting rows is not
enough.

---

## Trap 3 — the PDF

Do not assert about the PDF's binary. Test what is verifiable and stable:

- status 200 and `Content-Type: application/pdf`
- the row cap trips a 422 when the filtered set exceeds the limit, with a message
  pointing at the CSV
- below the cap, it does not trip

The cap's test is the most important of the three: it is what documents the design
decision about exporting at volume.

---

## Trap 4 — the consistency test

`InterestCalculator` has two faces (SQL and PHP) and they can drift apart
silently — rounding, `DECIMAL` precision against float, day counting.

Write a test with a matrix of cases covering:

- a billing within term, overdue by 1 day, by 30, by 400
- zero rate, high rate
- an amount with broken cents
- a billing paid within term and paid late

For each case, assert the SQL face and the PHP face return the same value down to
the cent. This is the test that upholds the requirement of a consistent result
across screens and report, and it is the first one a reviewer will look for.

---

## Trap 5 — totals

The totals come from a separate aggregation query, over the entire filtered set.
The easy mistake is for the test to pass by summing the first page.

Build a scenario with more records than fit on one page and assert the totals
correspond to the whole set, not to the page. Without that the test does not cover
the rule it claims to cover.

---

## Trap 6 — DDL inside a test

`TRUNCATE`, `ALTER` and any other DDL perform an **implicit commit** in MySQL. The
transaction `RefreshDatabase` opened dies there, Laravel detects it is gone and
marks `RefreshDatabaseState::$migrated = false` — which triggers a full
`migrate:fresh` before **every following test in the suite**, not just the guilty
class's. Measured in this project: ~50s per test against 0.3s.

Never clean a table in the teardown. What cleans is `RefreshDatabase`'s rollback. A
seeder invoked from inside a test cannot truncate either — `BillingVolumeSeeder`
bails out early when the tables are already empty for precisely that reason.

---

## Trap 7 — `withHeaders()` applies to the rest of the test

`$this->withHeaders([...])` is not for the next request: it keeps the header for
**all** the following requests in the same test. In a single-call test it makes no
difference, which is why it goes unnoticed.

With `Idempotency-Key` it is fatal. Paying with the key and then reversing
"without a key" sends the payment's key along with the reversal — a different path,
a different fingerprint — and the middleware answers 422 for a reused key. The test
fails for the wrong reason, or worse, passes for the wrong reason.

A header that changes between requests goes in the call's own argument:

```php
$this->postJson($uri, $body, ['Idempotency-Key' => $key]);
```

---

## Conventions

- Feature tests for everything that goes over HTTP; unit tests only for
  `InterestCalculator`. Two deliberate exceptions: `InterestCalculatorTest` lives
  in `tests/Unit` but touches the database, because the SQL face only exists inside
  MySQL; and `ReportIndexTest` lives in `tests/Feature` without going over HTTP,
  because what it verifies is the schema.
- The suite runs on **MySQL**, in the `billing_test` database. On SQLite the
  calculator's SQL face would be validating a different engine — `POW()` does not
  even exist by default.
- `RefreshDatabase`, and factories with named states (`overdue()`, `paid()`,
  `paidLate()`) rather than building dates by hand inside each test.
- One behaviour per test, with the name describing the rule and not the method.
- In the authentication tests, cover both sides: without a token it answers 401,
  and with a token it answers 200. The 401 alone does not prove the route works.
- Volume in the tests is small on purpose. The proof of performance is the seeder
  and the index documentation, not the suite.
- A consequence of that: **a small test does not prove behaviour at volume.** The
  PDF cap passed the suite with the wrong value, and only exporting against the
  real base showed it was an order of magnitude too high. Every decision about
  volume has to be measured outside the suite.
- An assertion about the execution plan (`EXPLAIN`) is about `possible_keys`, not
  about the chosen plan: on a small table the optimiser prefers a scan, and
  asserting `type != ALL` would fail for the wrong reason.
