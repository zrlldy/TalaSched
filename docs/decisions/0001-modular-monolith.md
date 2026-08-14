# ADR 0001: Use a Laravel modular monolith

- Status: Accepted
- Date: 2026-08-14
- Source: Formalizes the decision already present in `docs/architecture/ARCHITECTURE.md`; it does not change the architecture.

## Context

TalaSched spans organizations, academic structures, scheduling resources, timetables, approvals, templates, subscriptions, and audit. These domains share transactional invariants, especially tenant isolation, schedule publication, approvals, and entitlements. The current team and operational environment benefit from one deployment and one strongly consistent PostgreSQL database.

## Decision

Build and deploy TalaSched as a Laravel modular monolith with an Inertia/Vue frontend.

- Domain behavior belongs in cohesive actions, services, policies, queries, data objects, handlers, and jobs.
- Controllers and Inertia pages remain transport/presentation adapters.
- PostgreSQL is the authoritative transactional store; Redis and S3-compatible storage are supporting infrastructure.
- Module boundaries are enforced through ownership and dependency rules, not separate deployments.
- Existing domain-oriented directories are preserved. New code should converge toward the documented boundaries without a repository-wide mechanical move.
- Ports are introduced only for genuine external or replaceable boundaries: automatic optimization, billing providers, workbook processing/storage, malware scanning, and notifications where needed.

## Alternatives considered

### Microservices

Rejected for the initial product. They would introduce distributed transactions, contract versioning, tracing, deployment coordination, and partial failures before independent scaling or team ownership justifies those costs.

### Unstructured Laravel application

Rejected. It is operationally simple but would allow scheduling, academic, subscription, and approval behavior to become coupled through controllers and globally shared services.

### Full hexagonal architecture for every model

Rejected. Eloquent is appropriate inside the monolith. Repositories and ports for every persistence operation would add ceremony without protecting a real replaceable boundary.

## Consequences

- Cross-domain transactions and local development remain straightforward.
- Module ownership, permitted dependencies, and cross-module contracts must be documented and reviewed.
- Read-specific query objects are allowed without adopting full CQRS.
- Long-running work uses queues with explicit idempotency and tenant context.
- A future optimization service can be extracted behind the solver port if measured load or specialized runtime needs justify it.
- Directory convergence is incremental; file location alone is not evidence that a module boundary is complete.
