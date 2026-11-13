<?php
require __DIR__.'/config.php';
require_login();
require_once __DIR__.'/includes/chat.php';
$config=chat_config(); $providerLabel=$config['label']; $isGemini=$config['provider']==='gemini'; $isLocal=$config['provider']==='ollama'; $ready=chat_ready($config); $pageTitle='AI chatbot';
include __DIR__.'/includes/header.php';
?>
<main id="main-content" class="container chat-page">
    <div class="page-heading"><div><p class="eyebrow">YOUR WORKSPACE, IN A CONVERSATION</p><h1>A little help. A clearer plan.</h1><p class="muted">Talk through your priorities and turn the next step into action.</p></div><a class="btn ghost" href="tasks.php">View my tasks <?= icon('arrow-right') ?></a></div>
    <div class="chat-layout">
        <section class="panel chat-panel" aria-labelledby="chat-title" data-provider="<?= e($providerLabel) ?>" data-chat-ready="<?= $ready?'true':'false' ?>">
            <header class="chat-panel-header"><div class="section-title"><span class="section-icon violet"><?= icon('sparkles') ?></span><div><h2 id="chat-title">SmartTask chat</h2><p class="muted"><?= $ready?e($providerLabel).' · '.e($config['model']):'AI connection needed' ?></p></div></div><button class="btn ghost small" id="chat-clear" type="button">New chat</button></header>
            <?php if (!$ready): ?>
                <div class="chat-setup" role="status"><strong>Connect your AI to start chatting</strong><p>The chat interface is ready. <?= $isLocal?'Start Ollama with the configured model to enable local AI replies. No API key is needed.':'Add your selected provider’s API key on the server to enable AI replies.' ?></p><a class="text-link" href="#chat-setup-guide">See the setup steps <?= icon('arrow-right') ?></a></div>
            <?php endif; ?>
            <div class="chat-messages" id="chat-messages" role="log" aria-label="Conversation" aria-live="polite" aria-relevant="additions text"></div>
            <div id="chat-proposal" class="chat-proposal" hidden></div>
            <div class="chat-starters" aria-label="Suggested messages">
                <button type="button" class="chat-prompt" data-prompt="What should I focus on today, and why?">Plan my day <?= icon('arrow-right') ?></button>
                <button type="button" class="chat-prompt" data-prompt="Which of my tasks are overdue?">Review overdue tasks <?= icon('arrow-right') ?></button>
                <button type="button" class="chat-prompt" data-prompt="Create a high importance task called Prepare my project demonstration, due tomorrow at 5 pm.">Create a task <?= icon('plus') ?></button>
            </div>
            <form id="chat-form" class="chat-composer">
                <label for="chat-message">Message SmartTask</label>
                <textarea id="chat-message" name="message" maxlength="2000" rows="3" placeholder="Ask about your tasks, or describe what you want to do…" required <?= $ready?'':'disabled' ?>></textarea>
                <label class="chat-consent"><input type="checkbox" id="chat-consent" <?= $ready?'':'disabled' ?>> <span><?= $isLocal?'Use the local model on this computer to process my messages and task summary.':'Allow '.e($providerLabel).' to process my messages and task summary for this conversation.' ?></span></label>
                <div class="chat-composer-footer"><span class="field-help">Review every proposed change before it is saved. Ctrl / ⌘ + Enter to send.</span><button class="btn primary" id="chat-send" <?= $ready?'':'disabled' ?>>Send message <?= icon('arrow-right') ?></button></div>
                <p id="chat-status" role="status" class="chat-status"></p>
                <p id="chat-error" role="alert" class="chat-error" hidden></p>
            </form>
            <noscript><p class="notice">Enable JavaScript to use the chatbot. You can still manage tasks from My tasks.</p></noscript>
        </section>
        <aside class="chat-sidebar">
            <section class="panel"><p class="eyebrow">A USEFUL SECOND PAIR OF EYES</p><h2>From a question to a next step.</h2><ul class="chat-capabilities"><li><span><?= icon('tasks') ?></span><div><strong>Understand your workload</strong><p>Review active tasks, overdue work and the reasons behind your priorities.</p></div></li><li><span><?= icon('plus') ?></span><div><strong>Turn words into tasks</strong><p>Describe a task, its importance and its deadline in everyday language.</p></div></li><li><span><?= icon('check-circle') ?></span><div><strong>Move work forward</strong><p>Start or complete a task, or choose a new deadline. You approve the change.</p></div></li></ul></section>
            <section class="panel chat-transparency"><h2>How this AI works</h2><p>The selected language model interprets your message and suggests an answer or task action. SmartTask validates the action and waits for your confirmation.</p><p>The priority scores still come from the separate, transparent rules engine.</p><a class="text-link" href="assistant.php">Explore priority scoring <?= icon('arrow-right') ?></a><details><summary>What information is shared?</summary><p><?= $isLocal?'Your message, the last 6 conversation messages, task counts, and up to 20 active task summaries are processed locally by Ollama on this computer. No cloud provider receives them.':'Your message, the last 12 conversation messages, task counts, and up to 60 active task summaries are sent to '.e($providerLabel).'.' ?> Task notes and account details are excluded from the workspace snapshot; anything you type in chat is included.</p><p>The latest 24 messages are kept in your server session. New chat clears this conversation; signing out clears the session. AI responses may be inaccurate, so review them.</p></details></section>
            <?php if (!$ready): ?><section class="panel" id="chat-setup-guide"><p class="eyebrow">ONE-TIME SERVER SETUP</p><h2>Connect <?= e($providerLabel) ?></h2><?php if ($isLocal): ?><p>Start Ollama, then refresh this page. This installation uses <code><?= e($config['model']) ?></code> and needs no API key.</p><?php else: ?><ol class="chat-setup-steps"><li>Create an API key in your <a class="link" href="<?= $isGemini?'https://aistudio.google.com/apikey':'https://platform.openai.com/api-keys' ?>" target="_blank" rel="noopener noreferrer"><?= $isGemini?'Google AI Studio account':'OpenAI account' ?></a>. Check the provider’s current quota and billing terms.</li><li>Open <code>chat.local.php</code> in the project folder (copy <code>chat.config.example.php</code> if it is missing).</li><li>Paste the key into <code>api_key</code>, save the file, and refresh this page.</li></ol><p class="field-help">Keep the key on the server. Do not paste it into this conversation or commit it to source control.</p><?php endif; ?><?php if (!function_exists('curl_init')): ?><p class="chat-error">Enable PHP’s cURL extension before connecting.</p><?php endif; ?></section><?php endif; ?>
        </aside>
    </div>
</main>
<script src="assets/chatbot.js?v=<?= (string)filemtime(__DIR__.'/assets/chatbot.js') ?>" defer></script>
<?php include __DIR__.'/includes/footer.php'; ?>
