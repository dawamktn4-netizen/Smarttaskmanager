# Evaluation plan

The evaluation should test whether the implemented functions work and whether participants find the assistance useful. Functional testing and user research answer different questions; report them separately.

## Functional verification

The automated AI suite checks deadline boundaries, inactive tasks, score caps, ranking ties, historical sample thresholds and quiet-hour timing. The HTTP suite exercises registration, authentication, validation, ownership, task transitions and reminder delivery states using isolated QA accounts.

Record the software version, environment, test date, number of assertions passed, failures and corrective changes. A passing test suite does not establish usability or productivity benefit. Browser permission prompts, OS delivery behavior and prolonged real-world use require separate observation.

## Comparative task study

With the required institutional approval and participant consent, compare a basic manual-priority task list with the AI-assisted workflow using equivalent task scenarios. Counterbalance the order so practice does not always favor one condition. Keep task difficulty, deadlines, information and available time comparable.

Use anonymised participant identifiers. Define the sample-size rationale, inclusion criteria, stopping rules and missing-data handling before collection. Do not claim a statistically representative sample if recruitment does not support that conclusion.

| Research question | Measure | Interpretation limit |
| --- | --- | --- |
| Does assistance improve organisation and timely completion? | Task setup time, correct deadlines/categories, missed tasks, proportion completed before due time | A short demonstration cannot establish long-term productivity |
| Does prioritisation help identify tasks requiring attention? | First task selected, time to choose, rationale, agreement with independently specified scenario priorities | Importance can be subjective; report disputed priorities rather than forcing agreement |
| Is it useful and easy to use? | Participant ratings, observed errors, requests for help, and interview explanations | Ratings are self-reports; triangulate with observed behavior |
| Does reminder timing help without too much interruption? | Acceptance/change/ignore rate, snoozes, time-to-response and perceived interruption | Closing the tab or OS suppression can prevent delivery; separate these cases |

## Proposed questionnaire

Use a consistent five-point scale from strongly disagree to strongly agree. These are custom project items, not a validated replacement for a published scale.

1. I could create and organise a task without assistance.
2. The priority explanation helped me understand which task to work on next.
3. I could correct or override the system's suggestions easily.
4. The suggested reminder time was suitable for the task.
5. The reminders interrupted me more than I wanted.
6. The system would be useful for managing my own responsibilities.
7. I understood what the AI could and could not do.

Ask which feature helped most, what caused confusion, and what participants would change. If using a validated scale instead, follow its original item wording, scoring procedure and citation requirements rather than combining it casually with these custom items.

## Analysis and honest reporting

Present raw denominators with percentages and include distributions or individual paired outcomes when the sample is small. Report how many scenarios and users produced each measure. Choose statistical methods appropriate to the study design and actual data, with uncertainty intervals where justified. Do not manufacture participants, responses, significant differences or positive outcomes.

Discuss order effects, short study duration, self-report bias, unfamiliarity with the prototype, weak behavioral signals, modified deadlines, browser-only delivery and the selected sample. Keep negative findings and contradictory feedback. Explain which conclusions the evidence supports and which require a larger or longer study.

## Data collection record

Prepare columns for participant ID, condition order, scenario ID, start/end time, task choice, due time, completion time, reminder decision, delivery availability, questionnaire responses, observed errors and qualitative notes. Keep consent records separate from analysis data. Collect only the data necessary for the approved study.
