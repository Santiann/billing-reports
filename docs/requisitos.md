# Scope

This project was built to a written brief for a billing report application. The
brief itself is not reproduced here — it is someone else's document. What
follows is the scope in my own words, because the constraints it imposed are
what explain most of the architectural decisions recorded in the other files
under `docs/`.

**Required stack, not chosen:** PHP + Laravel as a REST API, Next.js with the
App Router and TypeScript on the front end, MySQL, and the whole thing running
under Docker Compose.

**Functional scope:** authentication, with every record and report behind it;
CRUD for customers and for billings; recording a payment against a billing.

**The business rule that drives the design:** an unpaid billing past its due
date accrues interest, and its updated value has to be available in real time.
Once paid, the amount freezes — the figures stored at the moment of payment are
what gets displayed from then on, never a recalculation.

**The report:** filter by period, status and customer, where the user chooses
which of the three dates (issue, due, payment) the period applies to; sort by
updated value; show totals over the entire filtered set; export to CSV and PDF.

**The constraint that shaped everything:** the report module had to be designed
for tables in the millions of rows, with a way to generate that volume for
testing rather than committing it. That single requirement is why the interest
calculation is expressed in SQL as well as in PHP — see
[arquitetura.md](arquitetura.md) for what follows from it, and
[performance.md](performance.md) for the measurements.

Documentation of AI usage was also part of the brief, and lives in
[ia.md](ia.md).
