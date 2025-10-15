# Flash-Sale Checkout System

A Laravel 12 API for managing flash sales with limited stock, handling high concurrency without overselling. The system supports temporary holds, checkout flow, and idempotent payment webhooks.

## Development Approach

This project was developed following **Use Case Driven Development (UCDD)** methodology, where each feature was implemented as a complete, testable use case. This approach ensures focused, incremental development and each use case is fully tested before moving to the next

### The 5 Core Use Cases

- UC1: View Product Details
- UC2: Create Hold
- UC3: Create Order from Hold
- UC4: Process Payment Webhook
- UC5: Auto-Expire Holds

## Assumptions & Invariants

### Database Invariants
1. **No Overselling**: `products.stock` ≥ 0 always (enforced via pessimistic locking)
2. **Hold Exclusivity**: Each hold can create at most one order
3. **Stock Accounting**: Total stock = available + active_holds + completed_orders
4. **Idempotency**: Same idempotency key always produces same result

### Business Rules
1. Holds expire after 2 minutes (configurable via `HOLD_EXPIRY_MINUTES`)
2. Expired holds are auto-released by background scheduler
3. Payment webhooks are idempotent and safe to retry
4. Stock is reserved when hold is created, released when hold expires or payment fails

### Concurrency Guarantees
1. Pessimistic locking (`SELECT ... FOR UPDATE`) prevents race conditions
2. Database transactions ensure atomic operations
3. Idempotency keys prevent duplicate webhook processing
4. Lock timeouts and deadlock detection with retry logic

## Setup Instructions

### Installation Steps

1. **Clone the repository**
```bash
git clone https://github.com/ibrahim-farhat/flash-sale-checkout.git
cd flash-sale-checkout
```

2. **Copy environment file**
```bash
cp .env.example .env
```

3. **Start Docker containers**
```bash
./vendor/bin/sail up -d
```

If you don't have Sail installed yet:
```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php83-composer:latest \
    composer install --ignore-platform-reqs
```

Then start Sail:
```bash
./vendor/bin/sail up -d
```

4. **Generate application key**
```bash
./vendor/bin/sail artisan key:generate
```

5. **Run migrations**
```bash
./vendor/bin/sail artisan migrate
```

6. **Seed the database**
```bash
./vendor/bin/sail artisan db:seed
```

7. **Start the queue worker** (for hold expiry processing)
```bash
./vendor/bin/sail artisan queue:work &
```

Or run it in a separate terminal:
```bash
./vendor/bin/sail artisan queue:work
```

8. **Start the scheduler** (for hold expiry checks)
```bash
./vendor/bin/sail artisan schedule:work &
```

The application will be available at `http://localhost`

## Running Tests

### Run All Tests
```bash
./vendor/bin/sail artisan test
```

## Manual Testing Guide

### Prerequisites
The API is available at `http://localhost/api` after setup. You can use `curl`, Postman, or any HTTP client.

## API Reference

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/products/{id}` | View product with available stock |
| POST | `/api/holds` | Create temporary hold |
| POST | `/api/orders` | Create order from hold |
| POST | `/api/payments/webhook` | Process payment webhook |

### Error Responses

All endpoints return appropriate HTTP status codes:
- `200 OK` - Success
- `201 Created` - Resource created
- `400 Bad Request` - Invalid input
- `404 Not Found` - Resource not found
- `409 Conflict` - Business rule violation (e.g., insufficient stock)
- `422 Unprocessable Entity` - Validation error
- `500 Internal Server Error` - Server error

## Logs & Metrics

### Application Logs
View real-time logs:
```bash
./vendor/bin/sail artisan tail
```

Or check log files:
```bash
./vendor/bin/sail exec laravel.test cat storage/logs/laravel.log
```

### Viewing Metrics
```bash
# Search for specific events
./vendor/bin/sail exec laravel.test grep "Lock acquired" storage/logs/laravel.log
./vendor/bin/sail exec laravel.test grep "Webhook already processed" storage/logs/laravel.log
./vendor/bin/sail exec laravel.test grep "Hold expired" storage/logs/laravel.log
```