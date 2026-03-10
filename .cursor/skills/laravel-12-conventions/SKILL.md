---
name: laravel-12-conventions
description: Enforces Laravel 12 code and testing conventions: minimal comments, PHP match/Enums, slim controllers with services, Laravel helpers, Blade directives, and PHPUnit tests. Use when writing or reviewing Laravel 12 PHP, Blade, migrations, or tests in this project.
---

# Laravel 12 Conventions

## General code

- Do not add comments or docblocks above methods or variables when they are obvious. Add comments only when the *reason* for the code needs explanation. Use type hints and descriptive names instead of `@var` unless explicitly requested.
- For new features, write PHPUnit automated tests (feature or unit as appropriate). Do not use Pest; this project uses PHPUnit.
- For library documentation: use Laravel Boost `search-docs` first. When a library is not covered there, use Context7 MCP to resolve the library id and fetch docs without being asked.

---

## PHP

- Prefer `match` over `switch` when possible.
- Define Enums in `app/Enums/`, not in `app/`, unless instructed otherwise.
- In migrations for enum-backed columns: set the default to the Enum value and cast the column to the Enum in the model.
- Avoid temporary variables used only once (e.g. prefer `auth()->user()` inline instead of `$currentUser = auth()->user()`).
- Use Enum cases (or `->value`) instead of hardcoded strings wherever an Enum exists — Blade, tests, seeds, config, routes, middleware.

---

## Laravel

### Controllers and services

- **Service injection**: If a Service is used in only one controller method, inject it into that method via type-hint. If used in multiple methods, inject in the constructor.
- Keep controllers slim; move larger logic into Service classes.
- Single-method controllers: use `__invoke()`. RESTful controllers: use `Route::resource()->only([...])`.
- Do not create a controller that only returns a view; use `Route::view()` with the Blade path instead.

### Eloquent and DB

- Register Eloquent Observers on the model with the attribute: `#[ObservedBy([UserObserver::class])]` and `use Illuminate\Database\Eloquent\Attributes\ObservedBy;`. Do not register observers in `AppServiceProvider`.
- Prefer Laravel helpers over facade imports: `auth()->id()`, `redirect()->route()`, `str()->slug()`, etc.
- Do not use `whereKey()` / `whereKeyNot()`; use explicit columns (e.g. `->where('id', '!=', $user->id)`).
- Use `User::create([...])`, not `User::query()->create([...])`.
- When adding columns in a migration, add those attributes to the model’s `$fillable`.

### Commands and structure

- Do not chain migration/model creation commands with `&&` or `;` — run each Artisan command separately to avoid identical timestamps.

### Livewire

- Use Livewire class components only; do not use Livewire Volt.

### Blade

- Use `@session()` for flash messages instead of `@if(session())`.
- Use `@selected()` and `@checked()` instead of raw `selected`/`checked` attributes. Example: `@selected(old('status') === App\Enums\ProjectStatus::Pending->value)`.

---

## Testing (PHPUnit)

### Before writing tests

1. **Database schema** — Use the `database-schema` tool to confirm:
   - Column defaults and nullability
   - Foreign key and relationship names
2. **Models** — Read the model to confirm relationship method names, return types, and related models (e.g. `author()` vs `user()`).
3. **Realistic data** — Do not assume empty model implies all nulls (check defaults). When asserting redirect-back with validation errors, use `assertSessionHasOldInput()` where relevant.

### When creating test data

- If a field is cast to an Enum, use that Enum (or its value) in the test data instead of raw strings.
