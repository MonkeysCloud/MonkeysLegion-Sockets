# MonkeysLegion v2 — Code Standards & Conventions

> **Version:** 2.0.0
> **PHP Requirement:** `^8.4`
> **Status:** Approved for v2 development

---

## 1. Core Principles

| Principle | Description |
|-----------|-------------|
| **Attribute-First** | PHP 8.4 attributes are the primary configuration mechanism. |
| **Type-Safe Everything** | Every property, parameter, and return type MUST be declared. PHPStan level 9. |
| **PSR Compliance** | PSR-1, PSR-4, PSR-7, PSR-11, PSR-12, PSR-15. |
| **Zero Magic** | No `__get`, `__set`, or `__call`. Use native property hooks and asymmetric visibility. |
| **Modular** | Every package must be installable independently. |

---

## 2. PHP 8.4 Language Standards

### 2.1 File Header
Every PHP file MUST start with:
```php
<?php
declare(strict_types=1);
```

### 2.2 Property Hooks & Visibility
Use **Property Hooks** to replace getters/setters and **Asymmetric Visibility** for read-only state.

```php
class User
{
    public private(set) int $id;

    public string $email {
        set(string $value) => $this->email = strtolower(trim($value));
    }

    public string $displayName {
        get => "{$this->name} <{$this->email}>";
    }
}
```

### 2.3 Banned Patterns
*   ❌ No `__get`, `__set`, `__call`.
*   ❌ No `extract()` or `compact()`.
*   ❌ No `@` error suppression.
*   ❌ No `global` variables.

---

## 3. Formatting & Style

### 3.1 Indentation & Spacing
*   **Indentation:** 4 spaces (No tabs).
*   **Line Length:** 120 chars soft limit, 150 chars hard limit.
*   **Braces:** Opening brace on **next line** for classes/methods; **same line** for control structures (`if`, `foreach`).
*   **Blank Lines:** Max 1 consecutive blank line.

### 3.2 `use` Import Grouping
Imports must be alphabetical within 4 distinct groups:
1.  MonkeysLegion Framework
2.  PSR / External Libraries
3.  PHP Built-in classes/functions
4.  Application classes

### 3.3 EditorConfig (`.editorconfig`)
Every project MUST include this file:

```ini
root = true

[*]
charset = utf-8
end_of_line = lf
insert_final_newline = true
trim_trailing_whitespace = true
indent_style = space
indent_size = 4

[*.{yml,yaml,json,js,ts}]
indent_size = 2

[Makefile]
indent_style = tab
```

---

## 4. Naming Conventions

| Element | Convention | Example |
|---------|-----------|---------|
| **Classes / Enums** | PascalCase, singular | `UserController`, `OrderStatus` |
| **Interfaces** | PascalCase + `Interface` | `QueueInterface` |
| **Methods / Props** | camelCase | `findById()`, `$firstName` |
| **Constants** | UPPER_SNAKE_CASE | `MAX_RETRIES` |
| **Routes** | kebab-case paths | `/api/user-profiles` |
| **Tables / Columns**| snake_case | `order_items`, `created_at` |

---

## 5. Architectural Standards

### 5.1 Attributes
*   Always use **named parameters**: `#[Field(type: 'string', length: 255)]`.
*   Attributes must be on their own line.

### 5.2 Controllers
*   MUST be `final`.
*   Constructor injection only.
*   Thin logic (delegate to Services).
*   Always return a `Response` object.

### 5.3 Services & DI
*   Use `#[Singleton]` for shared services.
*   Final classes preferred.
*   Depend on interfaces where possible.

---

## 6. Implementation Patterns (Quick Reference)

### 6.1 Entity Pattern
```php
#[Entity(table: 'orders')]
#[SoftDeletes]
class Order {
    #[Id] #[Field(type: 'bigInt')]
    public private(set) int $id;

    #[Field(type: 'string')] #[Cast(Status::class)]
    public Status $status;
}
```

### 6.2 DTO Pattern
```php
final readonly class CreateUserRequest {
    public function __construct(
        #[NotBlank] #[Email] public string $email,
        #[NotBlank] #[Length(min: 8)] public string $password,
    ) {}
}
```

### 6.3 Job Pattern
```php
#[OnQueue('default')]
#[MaxAttempts(3)]
final class ProcessPayment implements ShouldQueue {
    public function handle(PaymentService $svc): void { }
}
```

---

## 7. Testing & Quality
*   **PHPStan:** Level 9 mandatory.
*   **PHPUnit:** Coverage ≥80%.
*   **Naming:** `test_{action}_{scenario}_{expectedResult}`.

---

> **Document maintained by:** MonkeysCloud Team
