# Improvements that would be left for production

[← README](../README.md)

## Improvements that would be left for production

None of these is in the codebase. They sit outside what the brief asks for, and
building them would have widened the surface without answering it. Each one that
does get closed leaves this file, or stays with only whatever is left of it.

They are recorded because they are the ones this project's measurements actually
point at, not a generic list.

**Materialise the totals.** The per-scope cache
[was built](performance.md#cache-dos-totalizadores) and takes a one-year scope
from 12.9 s down to around 3 s on subsequent queries. But the first query for
each scope, and the first after each write, still pay the full aggregation —
which is O(n) by nature. An aggregates table updated by billing events would
remove that cost too, with one concern the cache does not have: the interest on
what is pending changes with the day, so the pending portion would need a daily
recompute.

**Partition `billings` by date.** With the report always scoping by period,
partitions by year or quarter would make scanning a wide scope proportional to
the scope, rather than to the table.

**A FULLTEXT index on `description`.** The search uses `LIKE '%term%'`, which is
not indexable because of the leading wildcard. Acceptable on a CRUD screen, not
on a base that grows.

**Read raw rows in the CSV export.** Measured: of the 55s an export of 56,680
rows takes, ~18s is the database and the rest is hydrating Eloquent models and
instantiating Carbon. `DB::table()` with a join trades the domain's convenience
for speed.

**Replace the PDF renderer if volume becomes a requirement.** `FPDF` or `TCPDF`
emit pages incrementally and do not assemble the whole tree, which would remove
the cap. It costs more laborious styling — the right trade when volume demands
it.

**Asynchronous export.** Above a certain size, generate it on a queue and notify
the user with a link, rather than holding an HTTP connection open for minutes.

**A read replica for the report.** Analytical queries competing with
transactional writes is the next bottleneck after the buffer pool.

**Observability.** Slow query logging with the execution plan — this project's
three performance findings all came from `EXPLAIN` run by hand, and that does not
scale as a practice.
