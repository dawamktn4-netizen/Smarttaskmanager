# Verification results

Verified on 22 September 2026 on local XAMPP with PHP 8.2.4 and the existing MySQL/MariaDB installation.

| Check | Result |
| --- | --- |
| Deterministic AI rules and reminder timing | 64 of 64 checks passed |
| HTTP integration and security checks | 114 of 114 checks passed |
| PHP syntax | All 24 PHP files passed |
| JavaScript syntax | Passed using the bundled Node runtime |
| Fresh database installation | Passed in an isolated temporary database |
| Repeat SQL import | Preserved the seeded user and task; six built-in categories remained unique |
| Desktop layout | Inspected at 1365 pixels with temporary sample records |
| Phone task layout | Inspected at 390 pixels; no page overflow; actions shown on task cards |
| Browser task creation | Created and displayed a task successfully |
| Browser AI reminder acceptance | Scheduled the selected suggestion successfully |
| Browser in-app notification delivery | Due alert displayed and dismissal confirmed |
| Reviewed AI reminders | Assistant acknowledged the existing decision and did not offer a new time |
| Browser console in inspected views | No warnings or errors reported |

## Scope

The HTTP suite covers authentication, session rotation, CSRF, rejected GET mutations, validation, escaped task titles, cross-account isolation, categories, task transitions, AI suggestion choices, quiet hours, disabled notifications, read-only notification polling, acknowledgement, snooze and dismissal. The functional assertion total is 178; syntax and visual checks are separate.

Only temporary QA accounts were used. Their records were removed after verification; the original account was preserved. Reproduce the deterministic suite with tests/ai_test.php and the HTTP suite with tests/http_smoke.py as described in README.md.

These checks establish the tested prototype behavior, not a security certification or evidence of improved grades or productivity. Browser/OS notification permission and closed-browser delivery are not covered by a successful HTTP test. The app intentionally requires an open active browser tab for alert delivery. Participant evaluation has not been performed; no study results are claimed.
