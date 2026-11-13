# SmartTask AI

[GitHub repository](https://github.com/dawamktn4-netizen/Smarttaskmanager)

A PHP and MySQL task and reminder manager aligned with the supplied Chapter One of **Smart Task and Reminder Management System using AI**. It combines task organisation, an explainable local expert system, controlled reminder suggestions, and an activity dashboard.

## Open the application

Start **Apache** and **MySQL** in XAMPP, then visit:

[http://localhost/smart-task-manager-php/](http://localhost/smart-task-manager-php/)

Existing accounts and tasks are preserved. The application automatically creates one new preferences table. No reset or re-import is required for an existing installation.

For a fresh installation, import `database/smart_task_manager.sql` through phpMyAdmin, open the application, and create an account. The SQL import is repeatable without dropping existing tables. Built-in categories are inserted only when missing.

Default local connection: MySQL host `localhost`, database `smart_task_manager`, user `root`, blank password. Optional environment variables: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`. Configure these on the PHP server, not in browser JavaScript. Requires PHP 8.0+ with PDO MySQL and mbstring, and MySQL/MariaDB. Developed and tested using XAMPP PHP 8.2.

## What is implemented

- Registration and sign-in, password hashing, session regeneration, POST sign-out.
- Create, edit, categorise, search, filter, start, complete, reopen, postpone and delete tasks.
- Current-time priority scores with individual explanations; scores refresh as deadlines approach.
- Adaptive reminder timing using recent completion hours and category delay history after a minimum sample size.
- Optional generative AI chatbot: local Ollama-powered task questions and reviewed create/start/complete/reschedule actions, with optional Gemini/OpenAI connections. Local mode needs no API key; see `docs/CHATBOT.md`.
- Interactive AI decision lab: preview changes to importance, deadline, progress and postponements, with before/after scores, ranking and reminder explanations. Experiments do not save task or reminder changes.
- User-controlled acceptance, changed timing and rejection of AI suggestions.
- Manual reminder scheduling, in-app due alerts, optional browser notifications, snooze and dismissal.
- Quiet hours, reminder preferences, personal categories and an account time zone.
- Dashboard, activity history and descriptive completion analytics based on actual records.
- Responsive layouts, labelled forms, keyboard focus indicators and useful empty states.

## What “AI” means in this project

The priority and reminder engine is a **local, rule-based expert system with simple behavioral statistics**. It is an implementation of symbolic decision support, not a generative chatbot or a trained predictive model. That engine needs no API key and sends no task data to an external AI service. The separate **AI chatbot** uses a local Ollama model in this installation, with optional Gemini/OpenAI providers; it processes chat messages and a bounded task summary with user consent and prepares actions for confirmation. Until configured, it displays a setup state rather than simulated AI answers. See `docs/CHATBOT.md` for activation, data handling and a demonstration sequence. The AI assistant shows the score factors and limitations. Users make all task and scheduling decisions.

See `docs/AI_DESIGN.md` for formulas, sample thresholds, quiet-hour handling, and limitations. Do not describe these scores as probabilities, model accuracy, or proof of improved productivity.

Open **Overview → Try the AI decision lab** to demonstrate the reasoning interactively. `docs/AI_VIVA_GUIDE.md` contains a two-minute demonstration, student-use examples, code locations and questions to prepare for. Demo data is identified on the assistant page because synthetic completion history also affects the profile. The fixed study-block prompt is a general strategy, not an AI task-duration estimate.

## Reminder delivery

Keep SmartTask open in a browser tab to receive reminders. The app checks for due reminders periodically. Browser notification permission is optional and requested only when the user selects the enable button. Reminders do not run as an operating-system background service, and no email/SMS delivery is included. Browser or OS settings can limit notification delivery. Quiet hours and disabled notifications suppress alerts; saved reminder records remain available on the Reminders page.

Dates use the account's local time zone selected at registration, preserving the original project's DATETIME design. The account time zone is not editable after registration because silently changing it would reinterpret existing deadlines. A future production version should migrate timestamps to UTC before adding travel/time-zone switching.

## Security and data handling

Prepared statements, server-side field validation, per-user ownership checks, escaped output, CSRF-protected POST mutations, secure password hashing, HttpOnly/SameSite cookies, and browser security headers are included. Login attempts are limited per session as a basic prototype safeguard; production deployment needs persistent rate limiting. Task completion disables its reminders. Reopening does not automatically re-enable old reminders. Deleting a task permanently deletes its reminders and task history through foreign-key cascades.

Use HTTPS, a dedicated least-privileged database account, secure credentials, tested backups, persistent rate limits, and a reviewed deployment configuration before public deployment. Local XAMPP defaults are for development.

## Verify and evaluate

Run the deterministic AI checks (chatbot validation and transaction checks are documented in `docs/CHATBOT.md`):

```sh
/Applications/XAMPP/xamppfiles/bin/php tests/ai_test.php
/Applications/XAMPP/xamppfiles/bin/php tests/ai_preview_test.php
```

The HTTP integration suite is described in `tests/http_smoke.py`; it creates isolated test accounts, and prints their identifiers for explicit cleanup. It must be run only against a development database. See `docs/THESIS_ALIGNMENT.md`, `docs/DEMONSTRATION.md`, and `docs/EVALUATION_PLAN.md` for requirement mapping, demonstration steps and a study protocol.

No research participants, usability results, grades or productivity gains are fabricated. The application supplies the implementation; the dissertation still needs real evaluation evidence and critical analysis.
