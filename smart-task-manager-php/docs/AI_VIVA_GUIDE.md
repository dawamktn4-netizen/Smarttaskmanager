# Explaining and demonstrating the AI

## A short explanation in your own words

“My project implements a local rule-based expert system for task prioritisation and reminder recommendations. It considers importance, time until the deadline, postponements, progress and recent category completion patterns. It calculates a score and explains each contribution. Reminder timing uses the user's preferred time or their most common recent completion hour, while respecting quiet hours. Users review the advice and remain in control.”

This is symbolic decision support with descriptive statistics. It is not a trained machine-learning model, a language model or a chatbot. The decision lab makes the existing reasoning interactive; it does not introduce a new learning algorithm. Ask your supervisor whether this implementation meets the module's specific AI requirement, particularly if a trained model is required.

## A two-minute demonstration

1. Open Overview and select **Try the AI decision lab**.
2. Use **Try an example assignment**. Its original medium-importance, three-day deadline scores 47.
3. Choose high importance and a deadline in 6 hours, then **Explain this change**. The score becomes 75: 35 importance points plus 40 urgency points.
4. Explain the factor bars and reminder preview. No task or reminder has been saved by this experiment.
5. Choose a real active task. Its current importance and progress load into the controls. Change one field, calculate again, and compare its original and hypothetical position.
6. Show **Timing that adapts** and **Review reminders**. Accepting, changing or ignoring a suggestion is a separate user action.
7. If [Demo] tasks exist, explicitly identify them as synthetic examples. Their history affects the profile and does not prove anything about a student's habits.

Without JavaScript, the same form submits to PHP and renders the result normally. With JavaScript, it updates the explanation without reloading the page.

## What students might gain

| Student problem | Implemented support | What still needs evaluation |
| --- | --- | --- |
| Several coursework and everyday deadlines compete for attention | Ranked tasks, visible reasons and a suggested next task | Whether students find the ranking relevant |
| It is hard to understand why advice changes | Decision lab shows score contributions and a before/after position | Whether the explanations improve understanding |
| Tasks are repeatedly postponed | Bounded postponement and category-history contributions | Whether these signals help rather than create pressure |
| Reminders interrupt rest or arrive at unsuitable times | Quiet hours, timing suggestions and manual review | Whether proposed timing is useful in real routines |
| An assignment feels too large to begin | A fixed suggestion to define one small outcome before a short work block | Whether students find this study prompt helpful |

The study prompt is a fixed suggestion, not an AI duration estimate. The system does not know a student's timetable, free time, assignment content, effort requirements or mental state. It cannot guarantee better grades, reduced stress or improved productivity. These are potential benefits to investigate, not measured findings.

## Code to show your teacher

- `includes/ai.php`: `ai_evaluate` calculates the five contributions; `ai_build_profile` summarises up to 200 valid completions within 90 days; `ai_rank_tasks` orders tasks; `ai_suggest_reminder` handles preferences and quiet hours.
- `includes/ai_preview.php`: runs the real scoring and ranking functions on a copied task at one shared time. It accepts only task IDs from the account's active-task query.
- `assistant.php`: requires sign-in and CSRF validation, validates preview input and returns escaped result markup. The lab performs no task or reminder writes.
- `assets/assistant.js`: submits the scenario, prevents overlapping requests and shows loading/error states. There is no second scoring formula in JavaScript.
- `includes/ai_lab_result.php`: shows the original score, hypothetical score, rank, factor contributions and reminder preview.
- `notifications.php` and `assets/app.js`: deliver reminders while the app is open; browser permission is optional. This is not background email or mobile push delivery.

## Questions to prepare for

**Why these weights?** They are explicit design heuristics. They have not been learned from a dataset or empirically optimised. Compare alternative weights and baseline rankings in a future evaluation.

**What adapts?** The preferred reminder hour uses observed completion times after at least three completions. A category with at least three completions can contribute up to five additional priority points based on delay rate. Completion time is only an imperfect proxy for a suitable reminder time.

**How is advice explained?** The displayed contributions sum to the score, including a cap adjustment if the raw total exceeds 100. A score is not a probability.

**How would you evaluate it?** First verify boundary cases and account isolation. Then, subject to the institution's research process, compare student task ordering, explanation understanding and reminder preferences against simple baselines such as deadline-only sorting. Record actual responses and avoid treating software pass counts as proof of user benefit.

**What changed since the thesis was written?** The interactive decision lab, the Overview entry point, a next-step study brief and clearer demo-data disclosure were added on 30 September 2026. The core scoring rules are unchanged. Update the implementation chapter and screenshots before claiming that the current thesis documents these additions.
