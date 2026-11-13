# Explainable AI design

This prototype implements a **local, rule-based expert system** with simple behavioural summaries. It does not call a language model, train a predictive model, or claim measured improvements in productivity. The implementation is in `includes/ai.php`; the AI Assistant page exposes every score contribution.

## Inputs and scoring

Only tasks owned by the signed-in account are loaded. Completed and cancelled tasks receive a zero score and are excluded from the assistant's active-task query.

| Factor | Rule | Points |
| --- | --- | --- |
| User importance | Low / Medium / High | 10 / 22 / 35 |
| Deadline | Absent / over 7 days / within 7 days / within 3 days / within 24 hours / within 6 hours / overdue | 0 / 5 / 15 / 25 / 35 / 40 / 45 |
| Postponements | 5 per recorded postponement, capped | 0–15 |
| Progress | Task is in progress | 0 or 5 |
| Recent category delays | Rounded value of 5 × delayed-completion proportion, after at least 3 category completions | 0–5 |

The total is capped at 100. A visible negative cap adjustment ensures the displayed factor contributions still sum to the displayed score. Levels are Low (<40), Medium (40–64), High (65–84) and Critical (85–100). Equal scores use the earlier deadline, then task ID, then input order. Missing deadlines sort last among equal scores. These weights are design choices; they have not been empirically optimised.

The short explanation is suitable for task lists; the assistant shows the full contribution table. A score is a ranking aid, not a probability. Recalculation updates derived fields without repeatedly adding audit-history events or changing the task's user-edit timestamp.

## Behaviour and cold start

The profile uses at most 200 completed tasks from the preceding 90 days, excluding missing, future, or invalid completion timestamps. Each task contributes its recorded local completion hour. After 3 observations, the most frequent hour becomes the reminder timing preference. Ties choose the hour closest to the user's configured preference on a 24-hour clock, then the earlier hour. With fewer than 3 observations, the configured hour is used and the interface explains the lack of history.

A category's delay proportion counts a completion once if it either finished after its recorded deadline or had at least one postponement. After 3 completions in that category, this proportion can add up to 5 priority points. The small cap limits the influence of sparse observations. This signal describes past records; it does not establish a cause or predict an individual's behaviour. Changing a task's category or deadline can change the resulting summary because the current stored record is used, not a reconstructed original deadline.

## Reminder recommendations

High, Medium and Low importance use nominal lead times of 24, 12 and 4 hours, respectively. The candidate day is based on that lead time and the clock time uses the completion-hour preference where possible. Candidate times are at least 15 minutes in the future. Quiet periods move a candidate forward to the end of quiet hours. If this passes an upcoming deadline, the engine tries the first permitted future time instead; if none is before the deadline, it returns no suggestion. Overdue tasks can receive a future follow-up and the explanation explicitly acknowledges the missed deadline. Tasks without deadlines can receive a gentle follow-up. Identical quiet-start and quiet-end times disable the quiet period.

An AI recommendation does not itself create, send, or accept a reminder. The reminder screen provides the user's accept, change and ignore controls. Disabled notifications suppress AI timing suggestions. The delivery capabilities and permissions are shown by the application; the scoring engine does not promise email, mobile push or background notifications.

## Time, privacy and safety

Dates follow the existing database convention: local wall-clock `DATETIME` values in the account's selected timezone. `ai_clock` applies that preference consistently; tests can inject a fixed clock. The engine uses strict date parsing and ignores invalid input. Quiet hours support both same-day and overnight intervals.

Local wall-clock storage is a prototype limitation: changing the account timezone reinterprets existing wall times, and a repeated hour during a daylight-saving transition cannot identify a unique instant without an offset. A production migration should preserve an IANA timezone and UTC instant per event rather than silently convert existing data.

No task content or behavioural profile is sent to an external service by this subsystem. Read and refresh database queries are scoped to the authenticated user ID. Priorities never modify the user's importance, status or deadline. Suggestions remain optional.

## Verification and dissertation evaluation

Run the deterministic checks with:

```sh
/Applications/XAMPP/xamppfiles/bin/php tests/ai_test.php
```

The script checks scoring boundaries, factor reconciliation, monotonicity, inactive tasks, tie ordering, cold start, bounded history, category signals, quiet hours, deadlines, overdue follow-up, disabled reminders and timezone handling. Successful checks demonstrate consistency with the implemented specification; they do not demonstrate improved productivity or model accuracy.

For a dissertation evaluation, record separately:

1. **Requirement verification:** Map each functional requirement to implementation evidence and a reproducible scenario; report failures as well as passes.
2. **Ranking relevance:** Ask consenting participants to rank representative tasks before viewing recommendations. Compare the engine with simple earliest-deadline and importance-only baselines using a stated metric such as pairwise agreement. Choose sample size and protocol before gathering data.
3. **Explanation usefulness:** Collect ratings of clarity and perceived control, plus open comments. Keep these opinions separate from objective task-completion measures.
4. **Reminder usefulness:** Log accepted, changed and ignored suggestions with participant consent. Compare timing acceptance before and after enough completion history is available; describe small-sample and selection limitations.
5. **Usability:** Observe task creation, explanation review, reminder editing, completion and history inspection. Report completion rates, errors and time on task for actual sessions.
6. **Ethics and limitations:** Explain consent, minimal collection, account separation and retention. Do not infer causality from self-selected participants or claim that passing software tests guarantees higher academic grades.

No participant study, prediction-accuracy result or productivity improvement is asserted in this document. Add those findings only after an actual, documented evaluation.
