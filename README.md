# SmartTask AI

SmartTask AI is a PHP and MySQL task and reminder manager for students. It includes explainable task prioritisation, reminder suggestions, a progress dashboard, and an optional AI chatbot.

## Run locally with XAMPP

1. Download or clone this repository. Copy the `smart-task-manager-php` folder into XAMPP’s `htdocs` folder.
2. Start Apache and MySQL in XAMPP.
3. For a fresh installation, import [`smart_task_manager.sql`](smart-task-manager-php/database/smart_task_manager.sql) through phpMyAdmin.
4. Open [SmartTask AI on localhost](http://localhost/smart-task-manager-php/).

## Try the demo account

Select **Create an account** in your local installation and register once using these example values.

| Field | Demo value |
| --- | --- |
| Full name | SmartTask Demo |
| Email address | `demo@example.test` |
| Password | `SmartTaskDemo2026!` |

Choose your time zone and submit the form. Then sign in with the demo email and password. Each fresh installation starts with its own database, so register the demo account and add sample tasks locally.

See the [project README](smart-task-manager-php/README.md) for full setup, AI features, and testing instructions.
