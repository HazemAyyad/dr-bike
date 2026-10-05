<!--
Sync Impact Report
- Version change: scaffold (unratified) -> 1.0.0
- Modified principles: template placeholders -> ten Doctor Bike backend principles
- Added sections: Backend and Data Constraints; Development Workflow and Quality Gates
- Removed sections: none
- Follow-up TODOs: none
-->
# Doctor Bike Backend Constitution

## Core Principles

### I. Single Source of Truth

- Existing Doctor Bike domain models and services MUST remain authoritative for product identity,
  inventory, stock movements, procurement, sales orders, customers, suppliers, debts, accounting
  entries, and financial balances.
- Online Store Management MUST act as a merchandising and commerce presentation layer over the
  existing inventory domain; it MUST NOT become a replacement inventory system.
- Store orders MUST use the existing `sales_orders` domain. A parallel online-order source of truth
  MUST NOT be introduced.
- Store credit and unpaid balances MUST post through the existing debt and accounting systems. A
  separate store wallet or debt ledger MUST NOT be introduced.

These rules prevent divergent records and preserve the reconciled operational and financial state.

### II. Explicit Domain Boundaries and Naming

- Online merchandising concerns MUST remain separate from physical inventory and location concerns.
- The existing `store_sections` domain represents physical inventory/store locations and MUST NOT
  be repurposed for online merchandising.
- New online-commerce tables, services, routes, and concepts MUST use an explicit `online_store`
  namespace or prefix where appropriate to make ownership unambiguous.
- Product, Customer, Seller/Supplier, SalesOrder, Debt, and accounting logic MUST NOT be duplicated.

Clear boundaries prevent similar terminology from creating accidental coupling or competing models.

### III. Server Authority and Financial Integrity

- Laravel MUST be authoritative for prices, discounts, stock availability, order totals, debt
  calculations, permissions, ownership, eligibility, and financial posting.
- Client-supplied totals, prices, discounts, balances, eligibility results, and authorization
  decisions MUST be treated as untrusted input and recalculated or verified server-side.
- Monetary operations MUST use existing accounting and ledger services where applicable.
- Financial and stock mutations MUST be atomic, transaction-safe, and preserve all existing
  accounting invariants.

This principle is non-negotiable because client trust or partial writes can corrupt stock and money.

### IV. Backward Compatibility

- The existing customer Store application and the `routes/api_store.php` compatibility layer MUST
  continue to work throughout the Online Store Management rollout unless an approved specification
  and migration plan explicitly replace them.
- Changes SHOULD be additive. Any breaking API or schema change MUST include an explicit migration,
  rollout, rollback, and compatibility plan.
- Existing Admin Flutter and Store Flutter clients MUST NOT be silently broken.
- Compatibility behavior MUST be removed only after known consumers have migrated and the removal
  is approved in the governing specification.

Compatibility protects production clients while the new capability is introduced incrementally.

### V. Unified Identity and Enforced Ownership

- Existing customers and suppliers MAY gain Store-user capabilities, but MUST NOT be represented by
  isolated duplicate business identities.
- Account linking MUST preserve one logical identity across users, customers, sellers, orders, and
  debts, with explicit, validated relationships.
- Ownership and authorization MUST be enforced server-side for every resource and action.
- App-created versus admin-created accounts and orders MUST be represented explicitly when required
  by the approved specification; origin MUST NOT be inferred solely from UI state or serial formats.

Unified identity keeps authorization, receivables, and history attributable to the correct party.

### VI. Security by Default

- Credentials, signing keys, tokens, secrets, and sensitive environment values MUST NOT be committed.
- Every resource and action MUST have server-side authorization, including ownership validation to
  prevent IDOR and cross-account access.
- Password reset, OTP, authentication, and account-linking flows MUST NOT expose verification secrets
  to clients or logs.
- Sensitive data MUST NOT be written to application logs.
- All external input MUST be validated, normalized where necessary, and safely handled before use.

Security controls MUST hold independently of client behavior or hidden Flutter controls.

### VII. Permissions and Auditability

- Administrative online-store actions MUST use the existing Doctor Bike permission model.
- Pricing, publication status, promotions, configuration, and financially significant actions MUST
  be auditable.
- Audit records MUST identify the actor, action, affected entity, and timestamp, and MUST capture
  previous and new state when that context is relevant to investigation or reversal.
- Backend permission checks MUST remain authoritative even when a client hides an unavailable action.

These requirements make sensitive changes attributable and reviewable without creating a parallel
authorization system.

### VIII. Testing and Regression Safety

- Every meaningful domain behavior change MUST include tests at the appropriate boundary.
- Financial, stock, order, debt, authorization, ownership, and compatibility flows MUST have feature
  or integration coverage; unit tests alone are insufficient for those cross-domain guarantees.
- Bug fixes SHOULD include regression coverage unless the spec or plan documents why reliable
  automation is impractical.
- Existing tests MUST continue to pass unless an approved specification intentionally changes the
  asserted behavior.
- Changes to legacy Store compatibility APIs MUST include regression coverage for affected contracts.

Tests provide executable evidence that new work preserves existing production invariants.

### IX. Migration and Data Safety

- Database migrations MUST be forward-safe and preserve existing production data.
- Destructive schema changes MUST NOT be made without an explicit, reviewed migration, backup,
  rollout, and recovery plan.
- Backfills MUST be deterministic, documented, and retry-safe where practical.
- Compatibility-sensitive columns SHOULD be nullable or have safe defaults during staged rollout
  when doing so avoids breaking existing writers and readers.
- Migrations MUST NOT assume a clean or newly created production database.

Production data is durable business state and must survive incremental deployment safely.

### X. Simplicity and Reuse

- Existing domain services MUST be extended when they already own the required behavior.
- New architecture, tables, services, or dependencies MUST have a concrete domain need documented in
  the approved specification or plan.
- Speculative abstractions and parallel implementations MUST NOT be added.
- Existing SalesOrder, DebtLedger/accounting, notification, permission, media, inventory, and product
  infrastructure MUST be reused whenever it correctly represents the domain.

The simplest compliant design reduces reconciliation risk and long-term maintenance cost.

## Backend and Data Constraints

- The Laravel backend owns all business validation and state transitions. Flutter clients MAY present
  intent and provisional displays but MUST NOT define authoritative business outcomes.
- Changes spanning inventory, sales, debt, or accounting MUST trace the complete write path through
  existing routes, validation, services, transactions, and ledger effects before implementation.
- New online-store persistence MUST store only genuinely new merchandising or commerce concepts and
  MUST reference existing domain entities instead of copying their authoritative attributes.
- External integrations MUST enter through validated boundaries and MUST NOT bypass permissions,
  domain services, transactions, or audit requirements.
- Production compatibility and data preservation take precedence over implementation convenience.

## Development Workflow and Quality Gates

- Substantial work MUST follow the Spec Kit sequence: constitution, specify, clarify when needed,
  plan, tasks, analyze/checklist when useful, then implement.
- Implementation MUST NOT begin until dependencies, acceptance criteria, domain ownership, migration
  impact, authorization, and compatibility expectations are clear.
- Every plan and code review MUST explicitly check affected constitution principles, especially
  source-of-truth ownership, financial integrity, permissions, compatibility, and data safety.
- Commits MUST be focused and reviewable. Unrelated refactors MUST NOT be mixed with feature work.
- Tests required by Principle VIII MUST pass before a change is considered complete. Any intentionally
  changed behavior or unavailable verification MUST be documented with its reason and risk.
- A deviation from this constitution MUST be documented and justified in the relevant specification
  and plan, then approved through the amendment procedure below before implementation.

## Governance

This constitution governs Doctor Bike backend design and delivery. Where a specification, plan,
task, convention, or implementation conflicts with it, this constitution prevails until formally
amended.

Amendments MUST:

1. State the proposed change and rationale.
2. Identify affected principles, production behavior, data, APIs, and client compatibility.
3. Include migration and rollback guidance when the amendment changes an existing guarantee.
4. Receive explicit project-owner approval before dependent implementation begins.
5. Update the version and amendment date in this document.

Versions follow semantic versioning: MAJOR for removal or incompatible redefinition of governance;
MINOR for a new principle or materially expanded requirement; PATCH for clarifications that do not
change obligations. Ratification establishes version 1.0.0.

Specifications, plans, tasks, and pull-request reviews MUST include a constitution compliance check.
Reviewers MUST reject unexplained violations. Periodic compliance review MUST verify that active
features, including Online Store Management, continue to use authoritative domains and satisfy
security, financial, test, compatibility, and migration requirements.

**Version**: 1.0.0 | **Ratified**: 2026-10-01 | **Last Amended**: 2026-10-01
