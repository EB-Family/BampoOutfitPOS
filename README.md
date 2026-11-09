# Bampo Outfit POS

A polished retail and inventory management system for a fashion business in Tanzania. The project combines a responsive POS interface, stock tracking, sales reporting, customer visibility, and a PostgreSQL-ready backend foundation for a real-world shop.

## What is included

- POS checkout flow with product browsing, category filters, cart controls, discounts, payment type selection, amount paid, and automatic change calculation
- Dashboard with KPIs for income, profit, low-stock alerts, recent sales, and best-performing products
- Inventory management screens for stock movement, purchase tracking, product master data, and low-stock warnings
- Sales, customer, returns, expenses, and reporting modules designed for retail operations
- PostgreSQL schema for users, roles, products, variants, sales, stock movement, suppliers, purchases, expenses, refund handling, and audit log trails
- PHP API foundation with session-based auth, role checks, password hashing, and transactional sales logic

## Run the local demo

Open the browser demo directly with:

```bash
python3 -m http.server 8000
```

Then open:

```text
http://localhost:8000
```

This serves the front-end from the project root and lets you use the POS demo with local storage persistence.

## Full backend setup

The project includes a PHP API in `api/` and a PostgreSQL-ready schema in `database-schema.sql`.

1. Copy the environment example:

```bash
cp .env.example .env
```

2. Update the database credentials and secret in `.env`.
3. Create the PostgreSQL database and import `database-schema.sql`.
4. Create an admin user:

```bash
php api/create_admin.php admin@bampooutfit.co.tz "Bampo Admin" "ChangeMe123"
```

5. Start the API:

```bash
php -S localhost:8080 -t api api/index.php
```

6. Start the frontend demo in another terminal:

```bash
python3 -m http.server 8000
```

## Quick launcher

Use the project launcher for both services together:

```bash
chmod +x start-pos.sh
./start-pos.sh
```

This runs the frontend on port 8000 and the API on port 8080 in the same terminal session.

## API highlights

Available routes include:

- `POST /api/auth/login`
- `GET /api/auth/me`
- `POST /api/auth/logout`
- `GET /api/products`
- `GET /api/sales`
- `POST /api/sales`
- `GET /api/purchases`
- `GET /api/stock`
- `GET /api/reports`

## Recommended upgrade path

This repository is already a strong POS foundation. The next production-grade step is to connect the front-end to the API, add a proper dashboard database layer, and expand the backend with customer management, advanced reporting, and multi-store support.

The app is structured so it can grow into a complete branded retail management system without changing the core business workflow.
