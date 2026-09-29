# Use of artificial intelligence

[← README](../README.md)

## Use of artificial intelligence

Development was carried out with **Claude Code**. The files that steer the agent
are in the repository, as the brief requires:

| File | Role |
|---|---|
| `CLAUDE.md` | Project instructions: the stack, the rule that governs the architecture, the two API origins, authentication, performance limits, commit order |
| `.claude/skills/laravel-report-tests/SKILL.md` | A skill triggered on testing work, carrying this project's specific traps |
| `.claude/skills/agent-browser/SKILL.md` | Browser automation: navigating the screens, taking screenshots and iterating on what is being built |
| `.claude/skills/github-actions-docs/SKILL.md` | GitHub Actions workflow syntax anchored in the official documentation rather than in memory |
| `.claude/skills/vulnerability-scanner/SKILL.md` | A vulnerability analysis routine — OWASP, supply chain, attack surface |
| `.claude/skills/crafting-effective-readmes/SKILL.md` | How to write a README per audience: a contributor, a reviewer, the author themselves a year from now |
| `.claude/skills/find-skills/SKILL.md` | Discovering and installing skills from the open ecosystem |
| `skills-lock.json` | The origin and hash of each skill installed from the ecosystem |

The middle three arrived ready from another project and were copied without
changes: a skill is versioned content, and rewriting one on import means losing
the version that has already been exercised elsewhere.

The last two come from public repositories — `softaworks/agent-toolkit` and
`vercel-labs/skills` — and that is why `skills-lock.json` exists, recording the
origin and the content hash of each one. It is the same reason a `composer.lock`
exists: a dependency with no pinned version is not a dependency, it is a bet. The
difference is that here it lands in the context of whoever writes the code.

Other skills guided specific commits **without being in the repository**: they
live in the developer's environment. They are declared here because the brief asks
for transparency about AI usage, and because in each case it is possible to point
at where they changed the outcome.

| Skill | Where it changed the outcome |
|---|---|
| `dataviz` | Rejected the dashboard chart's first palette for insufficient contrast between adjacent series — [ΔE 14.6 against a floor of 15](performance.md#dashboard) — and corrected the use of `tabular-nums`: proportional figures for an isolated value, tabular only in a column that aligns vertically |
| `frontend-design` | The [visual foundation](frontend.md#visual-foundation): typography with personality, semantic tokens and the decision not to use a single `dark:` class |
| `landing-page-design` and `copywriting` | The [public page](frontend.md#public-page): the structure above the fold, and the explicit refusal of fabricated statistics — [not one of its numbers is invented](frontend.md#not-one-of-the-pages-numbers-is-invented) |
| `vercel-react-best-practices` | Server Component patterns, and the fetch parallelism on the billing detail page, where the trail and the billing are fetched together rather than in sequence |

### What the configuration actually prevented

It is worth more to show where it changed the outcome than to describe it:

- **Frozen time.** The skill requires `travelTo()` in every test that touches
  interest. Without it, "overdue by 30 days" would change meaning every day and
  the suite would start failing on its own.
- **`streamedContent()`.** The skill warns that `assertSee` and `getContent()` do
  not work on a `StreamedResponse`. The CSV tests were born correct.
- **Nothing about the PDF's binary.** The skill bounds what is verifiable — the
  status, the content type, and above all the cap.
- **Totals against the page.** The skill describes the easy mistake exactly: build
  a scenario with more records than fit on one page and assert the totals cover
  the set. The test exists in that shape.
- **The test before the code.** On every business rule the test was written first
  and watched to fail. That is what made `InterestCalculator` be born with the
  consistency test between its two faces, which is the project's most important
  test.
- **A single source for the rule.** The factory's `paidLate()` state was
  deliberately left incomplete for two steps, rather than repeating the interest
  formula, until `RegisterPayment` existed to fill it in.

### Where the instructions were wrong

This matters as much as the rest: an agent's instructions are not revealed truth,
and three of them did not survive contact with measurement.

- **The PDF cap was 5,000.** The tests passed, because tests use few rows. The
  export against the real base blew the memory at 3,577. The measured curve showed
  5,000 would need more than 3 GB. The cap became 1,000, and the measurement was
  recorded beside the value.
- **The `backend` service name.** `CLAUDE.md` pins
  `API_URL_INTERNAL=http://backend`, and the infrastructure plan called php-fpm
  `backend` — which speaks FastCGI, not HTTP. Every Server Component fetch would
  have failed, and only inside Docker. nginx became `backend` and `CLAUDE.md`
  gained the note that the name is load-bearing.
- **`make lint` did not prove what it claimed to prove.** It passed on the
  developer's machine because the development server had generated Next's route
  types; on a clean clone the typecheck fails. What surfaced it was
  [CI](operacao.md#continuous-integration), on the first push. The missing step went
  into the target and the workflow, and the rule — a check that depends on a
  generated artefact has to generate it — went back into `CLAUDE.md`.

All of those corrections went back into `CLAUDE.md`, which is the point: the
configuration is kept alongside the code and corrected when the code proves it
wrong.
