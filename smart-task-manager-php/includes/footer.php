<footer class="app-footer">
    <span>
        SmartTask AI
        <span aria-hidden="true">·</span>
        Thoughtful planning, one task at a time.
    </span>

    <span>
        Dissertation research prototype
    </span>
</footer>

<?php if (!empty($_SESSION['user_id'])): ?>
    </div>
    </div>
<?php endif; ?>

<?php if (!empty($_SESSION['user_id']) && basename($_SERVER['SCRIPT_NAME']) !== 'chatbot.php'): ?>
<a class="btn primary chat-launch" href="chatbot.php"><?= icon('sparkles') ?> Ask SmartTask</a>
<?php endif; ?>

<script
    src="assets/app.js?v=<?= (string) filemtime(__DIR__ . '/../assets/app.js') ?>"
    defer
></script>

</body>
</html>