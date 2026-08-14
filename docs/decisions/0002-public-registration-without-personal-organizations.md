# ADR 0002: Keep public registration without personal organizations

- Status: Accepted
- Date: 2026-08-15

## Context

TalaSched supports organization-owned subscriptions and data. Faculty and other ordinary members must join an existing organization through an invitation or an explicit administrative action; a user account must not imply a separate school tenant.

The starter implementation kept registration public but automatically created a personal organization for every registered user. That behavior produced unintended tenants and made login, verification, membership removal, and organization deletion depend on a personal-organization fallback. The architecture review left the choice between public owner registration and invitation-only registration unresolved.

## Decision

Keep public registration enabled for prospective organization owners, but create only the user account during registration.

- A newly registered user may have no organization and is directed to organization setup.
- An unaffiliated user may create an organization and becomes its explicit owner, or review and accept an invitation addressed to their email.
- Faculty and ordinary members join organizations through invitations or future explicit administrative membership actions; registration never creates a personal organization.
- Login and email-verification responses use the current organization when one exists and otherwise direct the user to the organization page.
- Leaving, removal from, or deletion of the current organization selects another membership deterministically or clears the current organization when none remains.
- Existing rows previously marked personal become ordinary organizations when the legacy marker is removed; no organization data is deleted automatically.

## Alternatives considered

### Invitation-only registration

Deferred. It would prevent prospective owners from self-onboarding and require a request-access, sales-assisted, or pre-provisioning workflow that is not yet designed.

### Automatic personal organizations

Rejected. It assigns tenant and subscription semantics to individual accounts, creates isolated organizations for faculty, and conflicts with the organization-owned SaaS model.

### Automatically create a full organization for every public registrant

Rejected. Public registration expresses account creation, not necessarily authority to establish an institution. Organization creation remains an explicit action with a clear ownership transition.

## Consequences

- Authenticated users and shared frontend layout components must support a nullable current organization.
- Pending invitations must be reachable before a user belongs to any organization.
- Tests and factories must create organization ownership explicitly when tenant context is required.
- Invitation-token hashing, normalized roles, member limits, and initial subscription provisioning remain separate implementation tasks.
