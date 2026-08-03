# ADR 0001 — Frontend Architecture

- **Status:** Accepted
- **Date:** 2026-08-03
- **Decision owner:** Repository owner
- **Supersedes:** An earlier in-session selection of Livewire 3 + Tailwind

## Context

An interaction layer was selected earlier in the session **before the complete authoritative specification was available**. Section 16 of that specification names React, TypeScript, Inertia.js, Tailwind, shadcn-style components, Recharts, Vitest, and React Testing Library.

At the time of this decision the repository was empty: no application code, no schema, no Livewire implementation. Nothing existed that would need to be preserved or rewritten.

## Decision

**React + TypeScript (strict) + Inertia.js is the approved frontend architecture.**

Reasons of record:

1. It matches Section 16 of the specification.
2. It supports the reporting and dashboard requirements well.
3. Recharts integrates directly with React.
4. Strict TypeScript improves reliability for money, currency, report, and transaction data.
5. Vitest and React Testing Library match the required frontend testing stack.
6. Inertia allows Laravel to remain responsible for routing, authentication, authorization, and server-side data while providing a modern React interface.
7. The repository is empty, so choosing this stack does not require rewriting existing work.

## Consequences

- **Livewire is not introduced as a second frontend system.** Livewire and React are not mixed. Any future exception requires a strong documented reason and a new ADR superseding this one.
- The project carries a real frontend build (Vite), a client-side type system, and a frontend test suite.
- Money must cross the Inertia boundary as **strings**, never JSON numbers — JavaScript's `number` is IEEE-754 float64, which Section 3 forbids for financial values.
- Financial calculations do not live in React components (Section 16). The live profit preview is computed server-side so that preview and persisted values come from one implementation.

## Status of related questions

Closed. React, Inertia, TypeScript, Recharts, and the testing stack are final and are not reopened.
