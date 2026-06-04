# Target Application Architecture (Long-Term Direction)

## Overview

This application is evolving into a deterministic accounting and tax computation engine.
To support long-term scalability, correctness, and maintainability, the architecture should clearly separate:

- Domain (core accounting logic)
- Application (use cases / orchestration)
- Infrastructure (database, queries, external systems)
- UI (Livewire, controllers, DTO/view models)

The goal is to isolate business logic from framework concerns and protect accounting invariants.

# High-Level Target Structure

```aiignore
app/
├── Domain/
├── Application/
├── Infrastructure/
├── UI/
```

Laravel-required folders (Providers, Console, etc.) can remain where they are, but logically fall into Infrastructure or UI.

# 1. Domain Layer (Pure Business Logic)

```aiignore
   app/Domain
   ├── Tax
   │   ├── Pool
   │   ├── Lot
   │   ├── SuperficialLoss
   │   ├── Schedule3
   │   └── Shared
   │
   ├── Portfolio
   ├── Transactions
   ├── Accounting (future expansion)
   ├── Shared
       ├── Money.php
       ├── Currency.php
       ├── AssetQuantity.php
       ├── Decimal.php
       └── ValueObject.php (optional base)
```

## Domain Rules

The Domain layer contains:

- Value Objects (Money, Quantity, Currency, etc.)
- Aggregates (PoolLedgerEngine, LotLedgerEngine)
- Domain Services (SuperficialLossEvaluator)
- Invariant enforcement
- Domain exceptions

The Domain layer must NOT contain:

- Eloquent models
- Queries
- DTOs
- Livewire components
- Controllers
- Jobs
- Framework-specific dependencies

Domain logic should be deterministic and unit-testable without Laravel.

# 2. Application Layer (Use Cases / Orchestration)

```aiignore
   app/Application
   ├── Tax
   │   ├── BuildSchedule3.php
   │   ├── ProcessPoolLedger.php
   │   └── RecalculateTaxYear.php
   │
   ├── Portfolio
   └── Transactions
```

The Application layer:

- Coordinates domain objects
- Executes workflows
- Loads and persists via repositories
- Does NOT contain core accounting math

Think of this layer as “use case execution.”

# 3. Infrastructure Layer

```aiignore
   app/Infrastructure
   ├── Persistence
   │   ├── Eloquent
   │   └── Repositories
   │
   ├── Queries
   ├── Import
   ├── Reporting
   └── ExternalServices
```

Infrastructure contains:

- Eloquent models
- Database queries
- Repository implementations
- File import logic
- External integrations
- Reporting adapters

This layer depends on Domain, but Domain does not depend on it.

#  4. UI Layer

```aiignore
   app/UI
   ├── Livewire
   ├── Controllers
   └── ViewModels (DTOs)
```

UI contains:

- Livewire components
- Controllers
- Presentation DTOs
- View mapping logic

DTOs are presentation contracts and do not belong in Domain.

# Recommended Structure for the Tax Engine

```aiignore
app/Domain/Tax
├── Pool
│   ├── PoolState.php
│   ├── PoolDispositionResult.php
│   ├── PoolLedgerEngine.php
│   └── Exceptions
│
├── Lot
│   ├── LotLedgerEngine.php
│   └── ...
│
├── SuperficialLoss
│   ├── Evaluator.php
│   ├── Policies
│   └── ValueObjects
│
├── Schedule3
│   ├── Schedule3Calculator.php
│   └── ValueObjects
│
└── Shared
├── Money.php
├── Quantity.php
└── Currency.php
```

## Key Principle

All accounting logic lives inside Domain/Tax.

Nothing inside this folder should depend on:

- Eloquent
- Livewire
- DB queries
- HTTP
- Laravel framework features

# How Request Flow Works in This Architecture

## 1. UI Layer

Livewire / Controller triggers a use case.

## 2. Application Layer

- Loads data via repositories
- Passes domain objects into engines
- Receives results
- Persists results

## 3. Domain Layer

- Performs accounting logic
- Enforces invariants
- Returns deterministic results

# Migration Strategy (Incremental)

Do not refactor everything at once.

## Phase 1

- Introduce app/Domain
- Move new core objects (e.g., PoolState, PoolDispositionResult) there

## Phase 2

- Gradually move lot logic into Domain/Tax/Lot
- Keep Services as orchestration

## Phase 3

- Convert Services/Tax into Application/Tax
- Reduce logic inside Services

## Phase 4 (Optional)

- Move DTO into UI/ViewModels
- Move Eloquent & Queries into Infrastructure

# Architectural Principles to Preserve

- Domain is deterministic and framework-agnostic.
- Accounting invariants are enforced inside Domain.
- Infrastructure depends on Domain, not vice versa.
- UI depends on Application, not Domain directly.
- Persistence is an adapter, not part of the accounting engine.

# Long-Term Outcome

When fully matured, the system becomes:

```aiignore
Domain        = Accounting engine
Application   = Workflows / use cases
Infrastructure= Database & IO
UI            = Presentation
```

This structure supports:

- Multiple cost basis methods
- Expansion to additional tax regimes
- Deterministic replay of accounting
- Safer refactoring of financial logic
- High-confidence testing of tax invariants

# What Belongs in Domain/Shared

Only:

- Pure value objects
- Math abstractions
- Core primitives
- Cross-domain invariants

Examples:

- Money
- Currency
- AssetQuantity
- Decimal
- Percentage
- ExchangeRate
- DateRange

What does NOT belong there:

- DB helpers
- Formatting utilities
- Laravel-specific traits
- String helpers

Those go in Infrastructure or Support.

# Architectural Rule Going Forward

If a type:

- Encapsulates invariants
- Has domain meaning
- Is immutable
- Has no framework dependencies

→ It belongs in Domain.

If a type:

- Formats output
- Talks to the database
- Calls Laravel helpers

→ It does NOT belong in Domain.
