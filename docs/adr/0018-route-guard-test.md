# ADR 0018 — Guarding the Route Table with a Test

- **Status:** Accepted
- **Date:** 2026-08-20
- **Context:** Phase 7, Step 7.1

## Why not a review

Phase 7 asks for a security review. A review of the route table is worth something on the day it is done and nothing the next time somebody adds a route outside the middleware group — which is exactly the mistake it exists to catch, and exactly the mistake nobody notices.

So the review is a test. It runs on every commit and fails when a route becomes reachable without signing in.

## Decision 1 — Public routes are an explicit list with reasons

Every route must either carry `auth` middleware or appear in `PUBLIC_ROUTES` with a sentence saying why. Adding a route without authentication fails the test; the fix is to move it inside the group or to write down why it belongs outside. The second is a deliberate act, which is the whole point.

Keyed by **method and URI**, not by name: the two routes that accept credentials are unnamed, and a route can be renamed without changing what it exposes.

A second test asserts every entry still matches a real route, so a renamed route cannot leave behind an exemption that quietly covers nothing.

## Decision 2 — Authentication is tested separately from authorization

Signing in is not permission to read the books. A third test walks every section of the application with a user who holds no permissions at all and asserts each returns 403.

## What it found

Four routes outside the auth group:

| Route | Verdict |
|---|---|
| `POST login`, `POST register` | Correct — `guest` middleware, they must be reachable |
| `GET up` | Correct — Laravel's health check, reveals only that the app is running |
| `GET` and `PUT /storage/{path}` | Investigated |

The storage routes are registered by `FilesystemServiceProvider` whenever a local disk has `serve => true`, which the starter kit sets. `ReceiveFile` requires both `?upload=1` and a valid relative signature, so the endpoint was never open — the signature is the authentication.

It was, however, **unused**. This application stores no user files and serves none; the only thing touching `storage_path()` is mPDF's font cache, which goes through the filesystem and not the disk route. So `serve` is now `false`, and the two routes are gone.

Not a vulnerability fixed — a piece of the outside of the building that no longer has to keep being right.
