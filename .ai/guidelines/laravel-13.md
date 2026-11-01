# Laravel 13

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- This project uses the streamlined Laravel 11+ structure: register middleware, exceptions, and routing in `bootstrap/app.php` and service providers in `bootstrap/providers.php`. There is no `app/Http/Kernel.php` or `app/Console/Kernel.php`, and commands in `app/Console/Commands/` auto-register.
- CSRF protection is handled by `Illuminate\Foundation\Http\Middleware\PreventRequestForgery` (formerly `VerifyCsrfToken`, now a deprecated alias). Reference `PreventRequestForgery::class` in middleware stacks and `withoutMiddleware()`.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Eagerly loaded records can be limited natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.
- Do not create instances of a model inside its own `boot` or `boot*` methods; Laravel 13 throws a `LogicException`.

## Cache

- Cache plain arrays or scalars rather than PHP objects. If an object must be cached, add its class to `cache.serializable_classes`.
