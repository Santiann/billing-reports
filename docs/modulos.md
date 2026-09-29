# Modules and business rules

[← README](../README.md)

- [Customers module](#customers-module)
- [Billings module](#billings-module)
- [CSV import](#csv-import)
- [Billing report](#billing-report)
- [Access roles](#access-roles)
- [Payment idempotency](#payment-idempotency)
- [Audit trail](#audit-trail)
- [Payment reversal](#payment-reversal)

## Customers module

| Method | Route | |
|---|---|---|
| `GET` | `/api/customers` | a paginated list, with search, filtering and sorting |
| `POST` | `/api/customers` | creating |
| `GET` | `/api/customers/{id}` | viewing |
| `PUT` | `/api/customers/{id}` | editing |

The corresponding screens are at `/clientes`, `/clientes/novo`, `/clientes/{id}` and
`/clientes/{id}/editar`.

**Filtering, sorting and pagination happen in the database.** At no point is the set
loaded to be narrowed in memory — Next passes the parameters through and receives the
page already made.

### Two guards the API has to have

`sort` is validated against an allowlist (`name`, `document`, `email`,
`created_at`). It goes into the `ORDER BY`, and accepting the raw value would be
injection. A value outside the list answers 422.

`per_page` is capped at 100. Without that, `?per_page=999999` takes the API down with a
single request.

There is a test for both.

### Search

A single field, matched against name, email and document. The document only joins the
clause if the term has digits: without that guard, searching for "Aurora" would become
`document LIKE '%'` and bring back the whole table. The document is matched by prefix,
which uses the unique index; name and email use `LIKE %term%`, acceptable because the
customers table is small — the billings table, which is not, gets its own treatment in
the report.

### An unformatted document

It arrives from the screen as `123.456.789-01` and is stored as `12345678901`. Keeping
what was typed would make the search depend on the format whoever created the record
chose. The normalisation happens in the FormRequest's `prepareForValidation()`, before
the uniqueness rule runs — otherwise the same CPF with and without punctuation would
pass as two distinct customers.

### Server Actions for the mutations

Creating and editing use **Server Actions**, not Route Handlers. The reason is the
login's: the browser does not have the token, so the server is what talks to Laravel.
The Action reads the httpOnly cookie, attaches the `Bearer`, and returns the validation
errors field by field for the form to display — rather than collapsing them into a
generic message.

A Route Handler remains the choice where the browser needs a URL to navigate to or
download from: login, logout and the exports.

The filters live in the **URL**, not in component state: the page stays shareable,
survives a refresh, and the Server Component builds the already filtered query. The
success confirmation also arrives as a URL parameter, because it has to survive the
redirect the Action performs after saving.

### Validation messages in Portuguese

`lang/pt_BR/validation.php` covers the rules actually used, with `attributes`
translating the field names. Without it the screen would mix "The name field is
required." in with the custom Portuguese messages. `APP_LOCALE=pt_BR`.

---

## Billings module

| Method | Route | |
|---|---|---|
| `GET` | `/api/billings` | a paginated list, filtering by customer, status and description |
| `POST` | `/api/billings` | creating |
| `GET` | `/api/billings/{id}` | viewing |
| `PUT` | `/api/billings/{id}` | editing |

Screens at `/cobrancas`, `/cobrancas/nova`, `/cobrancas/{id}` and
`/cobrancas/{id}/editar`.

### Status and payment are not form fields

`status`, `payment_date`, `paid_amount` and `paid_interest_amount` are **not** in the
FormRequest's rules. Only what passes through `rules()` reaches `validated()`, so
sending them has no effect — there is a test posting `status: paid` and asserting the
billing is born pending.

The reason is integrity: accepting `status = paid` on creation would produce a paid
billing **without the frozen values**, and those values are not recoverable afterwards,
because the calculation is a function of the date the payment happened. The transition
to paid belongs to recording a payment.

For the same reason, **a paid billing cannot be edited**: changing the amount or the
rate would invalidate `paid_amount` and `paid_interest_amount`. The API answers 422, and
the edit screen redirects before serving a form that would only fail on submit.

### N+1

The listing shows the customer's name, and without eager loading that would be one
`SELECT` per row while serialising. The controller uses `with('customer')`, and
`BillingResource` uses `whenLoaded` — so the key disappears when the relation was not
loaded, rather than firing a query during serialisation.

There is a test that **counts the queries**: it creates ten billings from ten distinct
customers and asserts at most three queries (the pagination count, the billings select,
the customers select). Without eager loading it would be thirteen.

### The customer picker

The test base has five thousand customers, so a `<select>` with all of them is out. The
form uses a combobox that searches as you type, with a 300 ms debounce, through a Route
Handler — the browser does not have the token, so the server is what queries the API.
The selected id travels in a hidden input, so the form stays an ordinary form and the
Server Action does not need to know a combobox exists.

### Sorting defaults to `id desc`

It is the primary key: sorting by it costs no filesort. The other sortable columns
(`due_date`, `issue_date`, `original_amount`) did not have indexes at this point —
they arrive in `feat: add report indexes`, each one documented alongside the query it
serves.

Searching by description uses `LIKE '%term%'`, which is not indexable because of the
leading wildcard. Acceptable for a CRUD screen; the production alternative is a FULLTEXT
index, recorded on the improvements list.

### Performance observations, still without indexes

Preliminary measurements against the millions base, **before** the indexes. They are
recorded because they are what justifies what comes next:

A base of **2,000,000 billings**, with no concurrent writing:

| Query | Time |
|---|---|
| `SELECT COUNT(*) FROM billings` | **26.8s** |
| `ORDER BY due_date DESC LIMIT 15` (no index) | **3.5s** |
| `COUNT(*) WHERE status = 'paid'` | 1.2s |
| `ORDER BY id DESC LIMIT 15` (primary key) | 0.4s |

| Environment | |
|---|---|
| The `billings` table | 149 MB |
| `innodb_buffer_pool_size` | 128 MB (default) |

Under concurrent write load the numbers get much worse: the listing reached 5.3 minutes
and received a 504 from nginx, and the `COUNT(*)` went past 120s.

Two distinct problems show up here.

The first is the `COUNT(*)` Laravel's `paginate()` fires on **every request** to compute
`last_page`. It does not depend on the `LIMIT`: it walks the whole filtered set, every
time.

The second is that the table does not fit in the buffer pool. With 149 MB of data and a
128 MB pool, every full scan goes to disk — and that is what dropped the seeder's
insertion rate from ~1,900 to ~150 rows per second in the load's second half. (Measured
with the machine busy. The later re-measurement, with the machine idle and today's eight
indexes, is in
[indexes deferred during the load](performance.md#indexes-deferred-during-the-load).)

Both are addressed in `feat: add report indexes`, with measurements before and after.

---
## CSV import

`/clientes/importar` takes a file, shows what is going to happen and only writes once
confirmed.

**A partial import is the expected behaviour, not a failure.** A row with an error does
not stop the others from going in: the valid ones are imported, and the refused ones come
back named with the line number **from the file** — counting the header, which is how the
user finds it when they open the spreadsheet — and the reason. Aborting everything because
of a wrong email on line 47 would force them to fix and resend the whole file.

### The file is not kept between the preview and the confirmation

The common route would be to write the upload into a temporary directory, return an
identifier and use it on confirmation. That brings expiry, cleaning up abandoned files
and one more piece of state to get wrong.

Here the confirmation **resends the same file**, which is still in the browser's input.
The cost is one extra upload — trivial for a customer CSV — and the confirmation's report
comes out of the same code as the preview, so what the user saw is what happened.

That cost one defect that only appeared on screen: a `<form action={fn}>` is **reset by
React** once the action finishes, and the file field went back to "no file selected" — the
preview was wiping exactly what the next step needed, and the import button wrote nothing.
The action is now called from `onSubmit` inside a transition, which does not touch the
form.

### Streaming, and why

The file is read row by row with `fgetcsv` over a generator. Never `file_get_contents`
nor `file()`: a CSV with a hundred thousand customers cannot exist all at once in the
process's memory. There is a test asserting that importing 5,000 rows does not grow the
memory peak by more than 32 MB.

Writing goes in **batches of 500**, and each batch checks its documents against the
database in a single query. One query per row would turn ten thousand customers into ten
thousand queries; a batch insert without checking would hit the database's unique index
and bring down the 499 good rows along with the repeated one.

### Conveniences that come from whoever exports the spreadsheet

| | |
|---|---|
| Separator | detected — `;` from Excel in Portuguese or `,` |
| Header | accepts aliases: `nome`/`name`, `documento`/`cpf`/`cnpj` |
| Document | may arrive formatted; it is stored as digits only |
| Status | `ativo`/`active`, and empty assumes active |
| Excel's BOM | stripped before comparing the header |

Demanding an exact format would turn "the file does not work" into a support problem.

**A file missing the required columns is refused whole**, with a 422 on the upload field
— and the message gives the name the user has to type (`documento`), not the field's
internal name (`document`). There is nothing to import partially when you cannot even tell
what each column is.

### Billings: two extra rules

`/cobrancas/importar` uses the same reader and the same form, with two rules of its own.

**The customer is resolved by document**, not by id. The file comes from outside and does
not know the internal id; the document is the business identity both ends have. Resolution
happens **per batch**: one query brings back the customers for all 500 documents at once,
and there is a test asserting that a thousand billings spread across a hundred customers
does not exceed 20 queries — without batching it would be a thousand.

**The billing is born pending**, like one created through the screen. A status or
paid-amount column in the file is **ignored**, not accepted: accepting `paid` would create
a paid billing without the frozen values, which is the same reason the create form does
not have those fields. There is a test sending `status;valor_pago` in the file and
asserting the billing goes in pending.

Formats that come out of spreadsheets, all accepted:

| In the file | In the database |
|---|---|
| `1.234,56` or `1234.56` | `1234.56` |
| `09/08/2026` or `2026-08-09` | `2026-08-09` |
| `0,035` | `0.0350` |
| a missing rate | `0` — a billing with no interest is legitimate |

The date conversion uses `DateTimeImmutable` and not `CarbonImmutable`, and that is
deliberate: Carbon **throws** when the value does not match the format, instead of
returning `false` like the native one. Here the failing attempt is the normal case — four
formats are tried in sequence — and using an exception for expected flow is expensive and
reads worse. Both convert `32/13/2026` by rolling into the following month, so the date is
formatted back and compared against the original; without that, an invalid date would
become a billing with the wrong due date instead of an error on the row.

Unlike customers, **identical rows create two billings**: a billing has no natural key, and
two monthly charges for the same customer with the same due date are two real billings.

---

## Billing report

`GET /api/reports/billings`, with the screen at `/relatorio`.

| Filter | Values |
|---|---|
| `date_field` | `issue_date` · `due_date` · `payment_date` |
| `start_date` / `end_date` | the period, over the date chosen above |
| `customer_id` | |
| `status` | `pending` · `paid` · **`overdue`** |
| `sort` | the five columns, including `updated_amount` |

`overdue` is not a stored status: it is the derived condition `pending + due date in the
past`, and it comes from the same class that computes the interest — the rule has one
source, seen from two angles.

### The totals come from a separate query

The count, the original amount, the interest, the updated amount, received and pending all
come from **one aggregation query over the entire filtered set**, never from the sum of
the page on display. On page 3 of a thousand-billing report, summing the page would give a
meaningless number.

The test builds 25 billings on a page of 10 and asserts the total is 25, not 10.

`rows()` and `totals()` start from the **same filters object** — that is what guarantees
the footer talks about the same set as the rows, and it is what the exports reuse to
produce a file identical to what is on screen.

### Sorting by updated value

It is the reason the calculation exists in SQL. With it in PHP alone, sorting by updated
value would force loading the whole set into memory — which is what the brief rules out.
There is a test with a billing of a smaller original amount but far more overdue,
asserting the updated value flips the order.

Pagination has a tie-break on `id`: without it, two pages can repeat or skip rows when the
sorted column has ties.

### `whereDate()` is not used

Wrapping the column in `DATE()` stops MySQL from using the index, and the report is exactly
where that cannot happen. The columns are already of type `DATE`, and the comparison is
direct.

### Measured against 2,000,000 billings, before the indexes

| Query | Time |
|---|---|
| 1 month by due date (56,680 billings) | 3.8s |
| 1 month sorted by updated value | 3.5s |
| 1 month + the overdue filter | 4.2s |
| 1 year | 4.3s |

A month costs the same as a year, and that is the diagnosis: the cost does not come from
the size of the scope, it comes from scanning the whole table to find it. The `EXPLAIN`
confirms it:

```
EXPLAIN SELECT COUNT(*) FROM billings
WHERE due_date >= '2026-01-01' AND due_date <= '2026-01-31'

type: ALL      key: NULL      rows: 1989965
```

With `customer_id` alongside, the foreign key comes into play and the plan changes to
`type: ref`, `rows: 418`. In other words: the customer filter already has an index, the
date filter does not. That is what the next section solves.

---
## Access roles

Two roles: **administrator**, who operates, and **read-only**, who reads everything and
writes nothing.

| | Administrator | Read-only |
|---|---|---|
| Viewing customers, billings, the report and the dashboard | yes | yes |
| Exporting CSV and PDF | yes | **yes** |
| Creating, editing and importing | yes | no |
| Recording a payment | yes | no |

Exporting is reading, and it sits on the read-only side: the file is the same report in
another format, and refusing it to someone who can see the screen would be protecting the
data from the wrong place.

### The barrier is the backend, not the screen

The interface hides what the role cannot do, and that is **convenience**. Whoever knows
the endpoint's address reaches it without passing through any screen:

```bash
curl -X POST localhost:8000/api/customers -H "Authorization: Bearer <read-only token>"
# 403 — Seu perfil é de consulta e não permite esta operação.
```

`RoleAccessTest` hits the API directly, with no screen in the way, and covers **every**
endpoint that writes. A new write endpoint that does not appear there has no test, which
is the next signal.

Whoever types the address of a write screen gets the explanation — "seu perfil é de
consulta" — and not a form that will fail on submit nor a lying 404: the page exists,
what is missing is permission.

### Middleware and not a Policy

A Policy resolves authorisation **per record**: "this user may edit THIS billing". The
rule here is per **role** and holds for every record, so it is tied to the route group.

The gain is `routes/api.php`: you can read which routes write by looking at the file,
because they are in a single group, with `can.write`. Scattered across one policy class
per model, the same information would take opening four files.

### Two defaults that look like a contradiction

The `role` column defaults to **`viewer`** — the least privilege. A user created through a
path that forgot to set the role does not go off writing, which is the safe behaviour when
someone slips.

The test factory creates an **`admin`**. That is not a contradiction: the database's
default protects production, and the factory serves dozens of tests that need to write and
have nothing to do with roles. The opposite default there would make all of them fail with
a 403 for a reason that is not theirs.

And the users that already existed when the migration ran became administrators, despite
the default: before it there was no other role, so whoever was in there was an
administrator by definition. Applying the default to them would have stripped access from
whoever was already operating the system.

---

## Payment idempotency

Recording a payment is the API operation where repeating **charges twice** — and, since
[reversals](#payment-reversal), it is no longer the only one where repeating moves money
around. Creating two identical customers hits the document's unique index; reimporting a
CSV returns the report of what it wrote. Paying twice writes two frozen values, and the
second is another day's.

The user does not have to do anything wrong for that to happen: a double click, a
connection that drops after the server has processed, an `F5` on the confirmation screen.
The screen disables the button while submitting, and that solves the easy case and none of
the others — it is the same story as [access roles](#the-barrier-is-the-backend-not-the-screen):
what counts is what the backend guarantees.

The operation accepts the **`Idempotency-Key`** header, and with it the second call
returns the first one's result instead of processing again.

```
POST /api/billings/787/payment
Idempotency-Key: a446dee2-f551-4b68-a4ed-344dddf7301b

HTTP/1.1 200 OK
{"data":{"paid_amount":"13605.62","paid_interest_amount":"9593.85", ...}}

  ↓ the same call, again

HTTP/1.1 200 OK
Idempotent-Replay: true
{"data":{"paid_amount":"13605.62","paid_interest_amount":"9593.85", ...}}
```

The two bodies are identical byte for byte. The `Idempotent-Replay` header is the only
difference, and it exists so the caller can tell "just paid" from "had already paid" in the
log — the body alone does not tell that story.

The header's name was not invented: it is the IETF draft's
(`draft-ietf-httpapi-idempotency-key-header`), the same one Stripe and Adyen use. Choosing
a name of our own would mean explaining it on every integration.

### The unique index is the mechanism, not the validation

Reserving the key is an `INSERT` into a table with `UNIQUE (user_id, key)`, done **before**
processing. The obvious alternative — check whether the key exists and insert if it does
not — has a window between the two queries in which two simultaneous requests both get
through. And simultaneous requests are not the rare case here: they are the main case,
because that is how the double click arrives.

With the `INSERT` first, the database is what arbitrates. Whoever loses the race receives
the uniqueness violation and goes to look at the row's state to decide what to do.

The row is born with a null `response_status`, and that state — *reserved, still without a
response* — is what makes it possible to answer **409** to whoever arrives while the first
is still processing. Without it, the second request would have no way to know whether the
key is in use or the response simply does not exist.

Measured against the base of 2,000,000 billings, eight requests fired at the same time
against the same billing with the same key:

| Outcome | How many |
|---|---|
| `200` — processed the payment | 1 |
| `200 Idempotent-Replay` — received the stored result | 1 |
| `409` — arrived with the first in flight | 6 |

One billing, one payment. Without the key, all eight would have contended over the same
billing and the result would depend on who reached the `UPDATE` first.

### Middleware, and not code in the controller

The stored response has to include **the validation errors**: replaying a call that failed
has to replay the failure, not process it. And `RegisterPaymentRequest`'s 422 is born
before the controller exists — in the controller there would be nothing to store.

From the middleware you can store what the route answered, regardless of who answered it.
That works because `Illuminate\Routing\Pipeline` renders the exception inside the stack:
the middleware receives the 422 already as a response, not as a `ValidationException`.

The middleware is registered as the `idempotent` alias and applied to two routes: the
payment and the reversal. That is not laziness: applying it to the whole write group would
create a table row for every CSV import and every customer created, covering no risk at
all.

### What the key does NOT do

Three deliberate refusals, and all of them have tests:

**Without the header, nothing changes.** Paying an already paid billing still answers 422.
Idempotency serves whoever repeats the **same** operation — turning every second attempt
into a success would hide a real error.

**The same key with different content answers 422**, and "different content" includes a
different billing: the fingerprint compared is the method, the path and the payload. A
client that reuses a key has a bug, and returning the old result would hide the bug instead
of pointing at it.

**A server error gives the key back.** A 500 is not the operation's result, it is a failure
to produce one, and the right move after a 500 is to try again. Storing it would condemn
the key to replaying the failure for the next 24 hours.

### The validity is 24 hours

Keeping them forever is not an option: the table would grow without bound, and a key from
months ago would replay a response that no longer describes the record. Twenty-four hours
comfortably covers what idempotency exists to cover: the double click, the network retry,
the resubmitted form.

Cleaning up the expired ones is **by lottery** — a one-in-two-hundred chance, on every new
key. It is the same strategy Laravel uses to expire file-based sessions, and for the same
reason: maintenance cannot cost a `DELETE` on every write. A scheduled task would be more
predictable, but this project runs no worker — scheduling it here would mean writing a
cleanup that never runs.

### Where the key comes from, on the screen

The payment form draws a UUID **in the browser**, and reuses it as long as the fields'
content does not change:

- **same content → same key.** This is the double click and the resend after the
  connection drops. The backend returns the first result.
- **content changed → new key.** Someone who corrected the date after an error is asking
  for something else; reusing the key would hand back the old 422.

The key cannot be born in the Server Action. An action re-executed by a network retry would
run the draw again and produce another key — which is exactly the case the key exists to
cover. Born on the client, the resend sends the same one.

It also cannot be born during render: `crypto.randomUUID()` would give one value on the
server and another at hydration. That is why submitting goes through `onSubmit` with the
action inside a transition, the same pattern the
[import](#the-file-is-not-kept-between-the-preview-and-the-confirmation) already used for
another reason.

---
## Audit trail

Every **edit**, every **payment** and every **reversal** of a billing records who changed
it, what changed and when. The trail is read at `GET /api/billings/{id}/audit` and appears
at the bottom of the billing's page, as "Histórico de alterações" — including for the
read-only role, because reading the history is reading.

Each entry keeps **only what changed**, with the before and after values:

```json
{
  "event": "paid",
  "event_label": "Pagamento registrado",
  "user": { "id": 1, "name": "Administrador" },
  "changes": [
    { "field": "status",               "from": "pending", "to": "paid" },
    { "field": "payment_date",         "from": null,      "to": "2026-09-14" },
    { "field": "paid_amount",          "from": null,      "to": "9996.90" },
    { "field": "paid_interest_amount", "from": null,      "to": "3677.72" }
  ],
  "created_at": "2026-09-14T12:54:17+00:00"
}
```

The payment entry carries the frozen values in `to`, and the reversal's brings them in
`from`: the billing's payment columns are cleared, and what was paid stays recorded here.

A trail is worth what it guarantees, and there are three guarantees — each with a test.

### Complete: an observer, not an explicit call

The trail is written by an Eloquent observer on `Billing`, and not by a call at each point
that changes a billing. An explicit call is exactly the kind of thing the next write path
forgets; the observer catches every change through Eloquent, whether it comes from the
controller, from recording a payment, or from tinker.

The **event comes from the status transition**, not from the caller. No point in the code
declares "this is a payment": pending becoming paid is a payment, paid going back to
pending is a reversal, wherever it comes from. No new path can label it wrongly — the
reversal entered the trail without a new line in the observer, with just one more case in
the enum.

The observer's price is known: **a raw query slips past without warning.** A
`DB::table('billings')->update(...)` changes the billing and the trail never finds out.
That is why there is a test that sweeps `app/` looking for that pattern — and its limit is
stated in the test itself: it catches the write chained in the same statement, not the
builder held in a variable and updated three lines later. It exists so the obvious mistake
does not get past review, not to replace it.

The known libraries for this — `spatie/laravel-activitylog` and `owen-it/laravel-auditing`
— were considered and left out. Both lean on the same Eloquent events, so the raw-query
door would stay open just the same; they store everything in a generic polymorphic table,
designed to audit many models, where here there is one; and the distinction between a
payment and an edit would have to be written on top of them anyway. What exists here is an
observer, an enum and a model.

### Atomic: without the trail, no change

Editing and paying write with `updateOrFail`, which opens a transaction. The observer
writes the trail inside it, so if writing the trail fails, the change rolls back with it. A
billing changed without a record in the trail is the hole a trail cannot have.

The test simulates the failure on the trail model's own `creating` event, and not by
renaming the table: DDL mid-test would end `RefreshDatabase`'s transaction through an
implicit commit — [the trap that already cost 50 seconds per test](testes.md#the-seeders-test-emits-no-ddl).

### Immutable: a wrong record is corrected with another record

The trail's model **refuses `update` and `delete`** by throwing, and the table has no
`updated_at` because there is nothing to update. The foreign keys are `RESTRICT`: deleting
a billing or a user that has history fails, rather than taking the history along.

The refusal holds for every path that goes through Eloquent, and only for those. Raw SQL
with the application's user still changes the table. Closing that for real means database
permissions — an application user without `UPDATE` and `DELETE` on `billing_audits` — and
this project uses a single user for the application and for the migrations. It is stated
here rather than made to look solved.

### Only what actually changed gets in

What decides what changed are the model's casts. A value arriving as `"1000"` over a stored
`"1000.00"` is the same number, Eloquent does not mark it as changed, and it does not appear
in the trail — recording it would fill the history with noise that hides the real change.
The `updated_at` and `created_at` stamps are left out for the same reason, and an edit that
changes nothing records nothing.

### Creation is deliberately left out

What the brief asks for in the trail is edits, payments and reversals — changes. The when
of creation is already in `created_at`.

Recording creation **consistently** would require the id of every billing the import
writes, and the import writes in batches, with an `INSERT` of five hundred rows. MySQL does
not return the ids of a batch insert, and with `innodb_autoinc_lock_mode = 2` — MySQL 8's
default, checked on this server — the ids of one batch insert are not guaranteed to be
consecutive when there are concurrent inserts. Computing `LAST_INSERT_ID() + n` would be a
guess. The alternatives were writing row by row, undoing the batching the import uses, or
recording creation only for whoever creates through the screen.

The second is worse than not recording it: a trail in which half the billings have "created"
and the other half do not **lies by omission**. The trail starts, identically for every
billing, at the first change.

The volume seeder does not write a trail either. The two million rows go in through raw
inserts, and the paid ones receive their frozen values on the row itself, through
`RegisterPayment::freeze()` — no change was made by anyone to record. The seeder's
`truncate()` now clears `billing_audits` along with the rest, because the `TRUNCATE`
restarts the billings' ids and the old trail would end up describing billings that are not
its own.

### MySQL reorders the JSON's keys

`changes` is a JSON column, and MySQL does not preserve the order of its keys: it reorders
by size. `{"from": "a", "to": "b"}` comes back from the database as
`{"to": "b", "from": "a"}`, and `description` comes back after `due_date`. The test is what
showed it, with three assertions failing on order rather than on content.

The order the API delivers is imposed by the resource — `field`, `label`, `from`, `to`, and
the fields in the billing detail panel's order, with the status first — and the test
compares the stored content without depending on order, but with strict types:
`assertEquals` would solve the ordering by accepting `null` as equal to `''`, and the
payment's null `from` is precisely what matters.

### Reading it, measured at volume

The trail has no index beyond the foreign keys', and it does not need one. The read is
`WHERE billing_id = ? ORDER BY id DESC LIMIT 50`, and the index the foreign key creates on
`billing_id` is already in id order within each billing: in InnoDB a secondary index
carries the primary key at the end.

An empty table does not prove that — the optimiser picks a different plan when there are no
rows. The measurement was made with **200,080 synthetic entries** on the base of 2,000,000
billings, 81 of them on a single billing, deleted afterwards:

| Query | Plan | Time |
|---|---|---|
| a page of 50 for the billing | `Index lookup ... (reverse)` on the FK, no filesort | 0.127 ms |
| the count for the pagination | `Covering index lookup` on the FK | 0.049 ms |

---

## Payment reversal

A reversal undoes a payment that did not hold up — a bounced cheque, a reversed transfer, a
settlement posted against the wrong billing. `POST /api/billings/{id}/reversal`, with no
body, and on screen an "Estornar pagamento" card on a paid billing, for the administrator
role.

Three rules, each with a test:

- **The billing goes back to pending**, with the payment date and amounts null.
- **The frozen values do not disappear.** They go into the audit trail, in the reversal
  entry's `from`, and the original payment's entry stays there, untouched.
- **The interest starts running again from the original due date.**

### From the due date, and not from some other date

There were three candidate dates for the interest clock to restart from, and they give three
different values. A billing of R$ 1,000.00 at 2% a month, due on 16/05, paid on 20/05 and
reversed on 15/06:

| Interest running from | Days | Value on 15/06 |
|---|---|---|
| **the due date, 16/05** | **30** | **R$ 1,020.00** |
| the payment, 20/05 | 26 | R$ 1,017.31 |
| the reversal, 15/06 | 0 | R$ 1,000.00 |

The rule is the first. A payment that did not hold up did not happen as far as the debtor is
concerned: they still owe from the due date, and counting from the payment or from the
reversal would turn a reversal into an interest discount for someone who paid with a bounced
cheque.

### It needed no new interest rule

`ReversePayment` only clears the payment columns and returns the status to pending. The
interest starts running again with no line in the calculation, because `InterestCalculator`
only reads the frozen columns when the billing is paid — on both faces. It is
[the rule that governs the architecture](arquitetura.md#interest-calculation) paying off
again: if the updated value depended on something written at payment time beyond those
columns, the reversal would have to undo it in two places.

Checked on the base of 2,000,000 billings with a billing paid in 2023, two days after its
due date, for R$ 1,291.90. Reversed, it came to be worth **R$ 7,302.09** in all three places
the value can be read: the billing's endpoint (the PHP face), the SQL expression the listing
and the report use in the `SELECT`, and the arithmetic done separately, over the 1,067 days
since the due date.

A reversed billing can be paid again, and then the interest freezes at the new date.

### Reversal and idempotency

The reversal accepts `Idempotency-Key`, and the case that justifies it is concrete: paid,
reversed, paid again — and then the reversal's late retry arrives. Without the key, it would
**reverse the second payment**, which nobody asked to reverse. With the key, it receives the
original reversal's result.

The other side of the same question called for a decision: **does a reversal invalidate the
payment's key?** No, and that is on purpose. The key describes the act of paying, which
happened. A late retry of the payment arriving after the reversal receives the original
result, with `Idempotent-Replay`, instead of paying again. Invalidating the key on reversal
would turn that retry into exactly the double payment it exists to prevent. Whoever wants to
pay again after a reversal performs a new operation, with a new key — which is what the
screen does, because the payment form that reappears is another mount, with another draw.

The two tests covering that happen on the same day, on purpose: the key is valid for 24
hours, and a test that travelled from May to June would expire it and pass for the wrong
reason.

Writing them revealed a trap in the harness itself: `withHeaders()` keeps the header for
**all** the test's following requests. The payment's key leaked into the "keyless" reversal,
the middleware answered 422 for a reused key, and the test failed for the wrong reason. It
is recorded as trap 7 in the testing skill.

### No reason field

A reason for the reversal would be the obvious field, and it was left out. The brief does not
ask for it, and the who and the when are already in the trail. Adding it is not just a field
on the form: the trail is written by an observer that only sees the model, and the reason is
not a column on the billing. It would take a column in `billing_audits` and a way to pass
request context to the observer — machinery only worth building once the requirement exists.

### On screen: two steps, no modal

The first click only opens the confirmation, which says what is about to happen to the money
before it happens. The confirm button uses the destructive variant, which in this system is
the overdue colour — in this domain, red already means loss. A modal would be one more
component for a one-line question, and it would hide the paid amounts sitting just above,
which are exactly what someone needs to check before reversing.

### What it costs, and where the time goes

The first reversal on the real base took 3.3 seconds, and that number could not be left
unexplained. The first hypothesis — the 128 MB buffer pool forcing disk reads on the indexes
containing status and the payment columns — **fell on measurement**: writes with zero pages
read from disk took the same time.

What the time tracks is the **number of write transactions**. Measured on the base of
2,000,000 billings, three repetitions per scenario, with MySQL's counters calibrated against
what the measuring queries themselves add:

| Request | Median time | Write transactions |
|---|---|---|
| GET of one billing | 0.40 s | 1 |
| payment, no key | 0.46 s | 2 |
| reversal, no key | 0.58 s | 2 |
| payment, with a key | 1.41 s | 4 |
| reversal, with a key | 1.23 s | 4 |

The reversal's work in the database is small: the billing's `UPDATE` and the trail's `INSERT`
add up to **4 ms** measured directly in MySQL, inside a rolled-back transaction. The rest is
commit. An isolated commit — a one-row `INSERT` in autocommit — takes **183 to 360 ms** in
this environment, because durability is at maximum (`innodb_flush_log_at_trx_commit = 1`,
`sync_binlog = 1`, binlog on: redo and binlog both go to disk on every commit) and the disk
is Docker's inside WSL2. On a server with a decent disk the same fsync costs milliseconds;
what carries over from here is the proportion between the table's rows.

Each request's transactions, one by one:

- **The token's `last_used_at`.** Sanctum writes it on every authenticated request that lands
  in a new second — including the GET, which is why it makes a commit in order to read. Two
  reads in the same second took 0.51 s and 0.03 s.
- **The reversal's or the payment's transaction**, with the trail inside it.
- **Reserving the idempotency key**, before processing, and **storing the response**,
  afterwards. Those are the two the key adds, and they cannot be folded into the business
  transaction: the reservation has to be written *before* processing, otherwise the concurrent
  request does not see it and both process — which is the whole case the key exists to
  prevent.

Loosening durability (`innodb_flush_log_at_trx_commit = 2`) would remove most of that time,
trading it for up to a second of payments confirmed to the customer and lost in a server
crash. For a money write that is the wrong trade, and tuning MySQL's configuration belongs to
the [pending list](producao.md#improvements-that-would-be-left-for-production), with a
measurement of its own — not to a feature commit.
