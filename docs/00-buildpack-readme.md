# Sanabel Al-Rahma — Build Pack (Simplified)

Phase 1 spec for Claude Code. By **Takteek Agency**.

## Philosophy of this pack

Ship the **simplest thing that works correctly**. This is not an enterprise-grade
system and should not be built like one.

**We deliberately do NOT do:**
- Exhaustive edge-case handling — the listed cases only
- Abstraction layers "for later"
- Elaborate state machines — a `status` column is enough
- Micro-optimisation, caching, or scaling work
- Anything not written in these docs

**When in doubt: build the boring, obvious version.**

## The seven rules that are never simplified

Everything else is negotiable. These seven are cheap to implement and
catastrophic to omit, because this system moves donated money and holds
data on vulnerable families:

1. `transaction_ref` is unique in the database (one constraint)
2. Donors never see names, IDs, phones, or addresses (one resource class)
3. Nothing is hard-deleted (one `deleted_at` column)
4. Every write to a case or money is logged (one trait)
5. A verified payment is never edited — only reversed (one rule)
6. Basket reservation happens inside a DB transaction (one pattern)
7. Every assessment stores a snapshot of the values used (one JSON column)

Each is a few lines. Do not skip them.

## How to use

1. Copy into a new Git repo root.
2. Lock the stack in `CLAUDE.md` §1.
3. Start with `T-01` in `docs/05-backlog.md`. One task per session.

Prompt: `Read CLAUDE.md and docs/, then do T-07 only. Simplest working version. Show your plan first.`

## Language

Spec, code, identifiers, comments: **English**.
Everything a user sees: **Arabic, RTL**, via `lang/ar` files only.

## Files

| File | What |
|---|---|
| `CLAUDE.md` | Stack, conventions, hard rules |
| `docs/01-scope.md` | What we build, roles, what we don't build |
| `docs/02-data-model.md` | Tables and columns |
| `docs/03-rules.md` | Need engine and business rules |
| `docs/04-permissions.md` | Who can do what |
| `docs/05-backlog.md` | **Tasks in order — start here** |
| `docs/06-tests.md` | The tests that matter |
| `docs/07-decisions.md` | Open questions + defaults already chosen |
