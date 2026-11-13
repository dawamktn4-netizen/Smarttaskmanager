# Local AI — active setup

This installation now uses **Ollama with Qwen3 4B** on this Mac. No API key or cloud account is needed. The initial model download requires internet; normal chat inference runs locally through `127.0.0.1:11434`. Ollama is installed using Homebrew and starts at login (`brew services start ollama`). Stop it with `brew services stop ollama` when it is not needed.

The selected provider and model are in `chat.local.php`: `provider = ollama`, `model = qwen3:4b`. Keep Apache, MySQL and Ollama running. The chatbot checks that the model is available before enabling Send. If unavailable, start Ollama and refresh. It does not silently fall back to a cloud service.

Local inference uses up to 20 active task summaries, the last 6 conversation messages, an 8,192-token context and up to 1,400 output tokens. It may take up to two minutes on this 8 GB Mac. The browser displays the local provider and asks to use the local task summary. No cloud data sharing occurs in local mode. The smaller model can misunderstand dates or task references; review the actual action card before confirming.

The same server validation, confirmation tokens, ownership checks, transactions and audit trail apply to all providers. The model generates responses and proposed action fields; PHP performs only confirmed changes. This is integration of a pretrained model, not training a new model.

## Live local-model verification

Run `php tests/chat_local_live.php` to check real local-model replies using synthetic tasks and a fixed test date. The test does not save task changes. It checks planning, creation with a relative deadline, completion and rescheduling. The local parser accepts an equivalent SQL datetime with zero seconds and converts its format; it never guesses invalid dates. Proposed changes use a server-written review message so a model cannot falsely claim the change was already saved.

## Verified on this installation

On 8 October 2026, Qwen3 4B passed five live synthetic checks: planning, creating a task with a relative deadline, starting, completing and rescheduling. Observed replies took about 25–66 seconds on this Mac. The application also passed 43 validation and transaction checks. A browser test with the actual workspace correctly identified the highest-ranked task and its overdue status after explicit ranking and deadline facts were added to the model context. A browser test also rendered an accurate create-task proposal with low importance and no deadline, without saving a task. These results verify the tested cases, not every possible wording; continue reviewing action cards before saving.

## Optional cloud providers

Cloud setup below is optional and is not needed for the active local configuration. To switch, set `provider` to `gemini` (model `gemini-2.5-flash`) or `openai` (model `gpt-4.1-mini`) and supply the matching `api_key`. `CHAT_PROVIDER` overrides provider selection. Gemini supports `GEMINI_API_KEY` and `GEMINI_MODEL`; OpenAI supports `OPENAI_API_KEY` and `OPENAI_MODEL`. Obtain Gemini keys from https://aistudio.google.com/apikey. Requests never fall back between providers automatically.

Gemini uses its generateContent endpoint with a JSON response schema. The cloud adapters have parsing tests but have not been tested with live API credentials on this installation. Cloud mode sends up to 60 active task summaries and 12 recent messages to the selected provider only after consent. A provider change requires reloading the page and reviewing the recipient.

References: https://docs.ollama.com/api/chat and https://ollama.com/library/qwen3:4b and https://ai.google.dev/api/generate-content .

---

The following section describes the OpenAI option and shared behavior. Local mode differs in its context bounds, timeout and local data processing as described above.

# Generative AI chatbot

The **AI chatbot** page is separate from **Priority insights**. The chatbot uses an OpenAI language model through the Responses API; the existing priority engine remains a local rule-based expert system. A chat interface alone does not establish AI use. Until a valid API key is configured and a live request succeeds, the chatbot is not an operational AI integration.

## Activate on XAMPP

1. Create an OpenAI API project/key and enable API billing: https://platform.openai.com/api-keys . A ChatGPT subscription is not the application’s API credential.
2. Copy `chat.config.example.php` to `chat.local.php` in this project.
3. Fill `api_key` in that server file. The default model is `gpt-4.1-mini`; choose another Responses-compatible model with strict function calling if needed.
4. Open `chatbot.php` and refresh. Enable the on-screen data-sharing checkbox and send a message.

Alternatively, set `OPENAI_API_KEY` and optionally `OPENAI_MODEL` in the PHP/Apache server environment. Environment values override the local configuration. The PHP cURL extension and outbound HTTPS access are required. Connection errors, invalid credentials, model errors and usage/billing limits are shown without exposing the API key or raw provider responses.

`chat.local.php` is ignored by Git and direct HTTP access is denied by `.htaccess`. Never put credentials in browser JavaScript, documentation, screenshots or chat messages. Keep `.htaccess` protections enabled on Apache; on another server, configure equivalent access restrictions or use environment variables instead.

## Capabilities

- Answer questions about up to 60 active tasks (earliest deadlines first) and aggregate status counts.
- Explain the priority engine’s existing scores and suggest a study plan.
- Prepare a new task with a title, notes, importance and optional deadline. New tasks are pending and uncategorised.
- Prepare starting/completing an active task, or changing its deadline.
- Retain the latest 24 chat messages in the PHP session, with the latest 12 used as conversation context.

The chatbot cannot delete tasks, browse the web, access a calendar or schedule reminders. It should ask for a precise time when the requested deadline is ambiguous. Task notes are excluded from the workspace snapshot. Notes explicitly typed into chat are still sent as part of that message.

## Request and confirmation flow

1. The authenticated, CSRF-protected endpoint builds an owner-scoped snapshot, including current local time and account timezone.
2. The OpenAI Responses API receives instructions, the snapshot, recent conversation and the message, with `store: false` and a strict `respond` function schema.
3. The model returns text and, optionally, one proposed action. PHP independently validates types, fields, allowed actions, dates and task ownership. No model-written SQL is executed.
4. A proposal is stored on the server with a random confirmation token, owner, expiry (15 minutes), and task fingerprint. The browser shows the exact title, deadline, importance, notes and applicable side effects.
5. Only **Confirm change** executes it. PHP checks ownership and freshness again, locks the task and commits the update plus audit entries in one transaction. Changed tasks require a new proposal.
6. Completing disables reminders. Rescheduling preserves reminder times and warns the user. Moving an existing deadline later increments the postponement count. Derived priorities are refreshed.
7. Confirmation receipts prevent repeated POSTs in the same session from duplicating an action. Cancel/New chat invalidate pending proposals. A successful new message replaces any unconfirmed proposal.

Messages and task titles remain untrusted. Prompt instructions reduce unintended tool proposals, while ownership checks, field validation and explicit review enforce the actual boundary. AI-generated statements can still be wrong and need human review.

## Data handling and limits

The user must opt in before sending each conversation. OpenAI receives messages, recent history, task counts and the bounded active-task snapshot. The app does not send account email, credentials or task descriptions in that snapshot. The `store: false` request setting is not a promise of zero provider retention; consult the provider’s current data policies.

The prototype allows eight model requests per minute per session, limits input to 2,000 characters and output to 1,600 tokens, and times out remote calls after 45 seconds. This is a session limit, not production account-wide abuse protection. Set provider project budgets/alerts before broader deployment. The PHP session lock is held during a request to serialize chat actions; requests in the same session can briefly wait.

## Verification and demonstration

Run:

```
/Applications/XAMPP/xamppfiles/bin/php -d session.save_path=/tmp tests/chat_test.php
/Applications/XAMPP/xamppfiles/bin/php tests/ai_test.php
/Applications/XAMPP/xamppfiles/bin/php tests/ai_preview_test.php
```

The chat test uses connection-local temporary tables cloned from the existing schema; it does not change existing user records or call OpenAI. Provider output fixtures validate response parsing; they are not evidence of a live AI response. A real model request must also be tested after configuration.

For a real demonstration after activation:

1. Ask “What should I focus on today, and why?” and compare the answer with My tasks.
2. Ask “Create a high importance task called Prepare my presentation, due tomorrow at 5 pm.” Review the interpreted full date/time, then confirm.
3. Check My tasks and Activity log for the saved task.
4. Ask to move that task’s deadline, cancel once to show nothing changes, then ask again and confirm.
5. Ask to complete it, confirm, and show its completed status.

Explain the architecture accurately: the model understands language and proposes structured actions; PHP validates and executes confirmed actions; a separate rules engine computes priority. You are integrating a pretrained model, not claiming to have trained it. Evaluation still requires real test results and user evidence.

Official references:
- https://developers.openai.com/api/docs/guides/function-calling
- https://developers.openai.com/api/docs/models/gpt-4.1-mini
