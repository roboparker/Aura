# API Development Guide

## Creating an Entity

Entities are PHP classes in `api/src/Entity/` with API Platform and Doctrine attributes.

### Example Entity

```php
<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(mercure: true)]
#[ORM\Entity]
class Book
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    private ?int $id = null;

    #[ORM\Column]
    #[Assert\NotBlank]
    public string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
```

This single class auto-generates:
- `GET /api/books` (collection)
- `POST /api/books`
- `GET /api/books/{id}`
- `PUT /api/books/{id}`
- `PATCH /api/books/{id}`
- `DELETE /api/books/{id}`
- OpenAPI documentation at `/docs`
- Real-time Mercure updates on changes

## Database Migrations

After modifying entities:

```bash
bin/console doctrine:migrations:diff    # Generate migration
bin/console doctrine:migrations:migrate # Apply migration
```

Migrations live in `api/migrations/`.

## Validation

Use Symfony Validator constraints as attributes:

```php
#[Assert\NotBlank]
#[Assert\Length(min: 3, max: 255)]
#[Assert\Email]
```

## Filtering & Pagination

API Platform provides built-in filters:

```php
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;

#[ApiFilter(SearchFilter::class, properties: ['title' => 'partial'])]
```

Pagination is enabled by default (30 items per page).

## Testing

Tests use PHPUnit and live in `api/tests/Api/`:

```bash
bin/phpunit                        # Run all tests
bin/phpunit tests/Api/BookTest.php # Run specific test
```

API test pattern: extend `ApiTestCase` from API Platform.

## Serialization Formats

Supported out of the box: JSON-LD, JSON:API, HAL, JSON, XML, CSV, YAML.
Content negotiation via `Accept` header.

## Security

Configure authentication in `api/config/packages/security.yaml`. API Platform integrates with Symfony's security system for access control on operations.
