# Demonstration guide

Use a dedicated demonstration account so practice data cannot be confused with real research observations. Start Apache and MySQL in XAMPP and open http://localhost/smart-task-manager-php/.

## A six-minute walkthrough

1. **Create an account and sign in.** Explain that users have separate workspaces. Choose the correct local time zone at registration.
2. **Organise work.** Add three clearly labelled demonstration tasks: one high-importance task due tomorrow, one medium-importance task due in three days, and one low-importance task without a deadline. Assign different categories and add a concrete next step to each.
3. **Explain prioritisation.** Open AI assistant, expand the factors and account for the point total. Change one task's importance or postpone it, then explain why the recommendation changes. State that the rule weights are prototype design choices.
4. **Review a reminder suggestion.** Show its timing explanation and accept/change/ignore controls. Accept one, change another and ignore a third. Confirm that these decisions do not alter the original task deadline.
5. **Demonstrate delivery.** In Settings, ensure alerts are enabled and the current time is outside quiet hours. Schedule a manual reminder one minute ahead. Keep the tab visible. When the in-app card appears, snooze or dismiss it. Browser permission is optional. Polling runs every 30 seconds, so delivery may occur up to approximately 30 seconds after the due time while the tab is active.
6. **Show progress.** Complete a task. Show the dashboard, activity history and insights updating from the real record. Reopen it to demonstrate reversible status control and explain that old reminders stay disabled.

## Questions to be ready to answer

**What type of AI is this?** A local symbolic expert system with descriptive behavioral adaptation. It is not a language model or a trained classifier.

**Why use these weights?** Importance and urgency dominate; postponements and prior delays add smaller bounded adjustments. The choices are explicit and testable. A study is needed to judge whether they match users' priorities.

**What happens with no history?** The system uses task information and the preferred reminder hour. It only adapts timing after at least three recent completions.

**Can the AI change my schedule?** It suggests priorities and reminder times. The user must make the scheduling decision.

**What happens when the browser is closed?** The prototype cannot deliver alerts. Saved due reminders become eligible when an active tab is opened outside quiet hours.

**What does the chart prove?** It describes task records and completion actions. It does not prove improved productivity or establish causation.

## Before presenting

Run the AI tests, confirm both XAMPP services are running, and rehearse using the actual computer and browser. Check dates, the account time zone, quiet hours and notification preferences. Keep a backup of the application and database. Never present demonstration data as study results.
