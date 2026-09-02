# Cafeteria

A lightweight school cafeteria management application built with PHP and SQLite.

Cafeteria combines student registration, lunch service, menu management, and basic sales reporting in a simple web interface. It is designed as a compact, self-contained application with no framework or external database server required.

## Features

- Create student cafeteria profiles with grade, lunch time, guardian information, and meal preferences
- Serve lunches through a simple point-of-sale workflow
- Add menu items, set prices, and show or hide items from the serving screen
- Record individual meal sales and item details
- View monthly sales totals and meals served
- See recent sales and popular menu items
- Browse registered students
- SQLite persistence with foreign keys and WAL mode
- CSRF protection, prepared SQL statements, and HTML escaping
- Responsive dark-mode interface

## Tech

- PHP 8+
- PDO / SQLite
- HTML and CSS
- No application framework
- No JavaScript dependency

## Running locally

The application stores its database outside the public project directory at:

```text
../private_html/cafeteria.sqlite
```

Create that directory first:

```bash
mkdir -p ../private_html
```

Then, from the `Cafeteria` directory, start PHP's built-in server:

```bash
php -S 127.0.0.1:8000
```

Open:

```text
http://127.0.0.1:8000
```

The SQLite database and default menu items are created automatically on first run.

## Default menu

A fresh database starts with:

- Daily Lunch
- Fresh Fruit
- Milk

Menu items can then be added, priced, hidden, or restored from the application's **Manage menu** screen.

## Project structure

```text
Cafeteria/
├── index.php
└── .gitignore
```

The entire application is intentionally contained in `index.php`, while runtime SQLite files are kept outside the web-facing repository.
