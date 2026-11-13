<p class="eyebrow"><?= $labResult['example']?'EXAMPLE ASSIGNMENT · PREFERENCES ONLY':'YOUR TASK · CURRENT COMPLETION PROFILE' ?></p>
<h3><?= e($labResult['title']) ?></h3>
<div class="lab-scores">
    <div><span>Original score</span><strong><?= (int)$labResult['before']['score'] ?><small>/100</small></strong></div>
    <span aria-hidden="true">→</span>
    <div><span>What-if score</span><strong><?= (int)$labResult['after']['score'] ?><small>/100</small></strong></div>
</div>
<p><span class="pill"><?= e(ucfirst(strtolower($labResult['after']['level']))) ?> priority</span> <strong><?= $labResult['delta']>0?'+':'' ?><?= (int)$labResult['delta'] ?> points</strong> compared with the original.</p>
<?php if($labResult['rank_after']!==null): ?><p>Position <strong><?= $labResult['rank_before'] ?> → <?= $labResult['rank_after'] ?></strong> of <?= $labResult['total'] ?> active tasks if only this task changed.</p><?php endif; ?>
<p><?= e($labResult['after']['reason']) ?></p>
<div class="lab-factors" aria-label="Score contributions">
    <?php foreach($labResult['after']['factors'] as $factor): ?><div><span><?= e($factor['label']) ?></span><strong><?= $factor['points']>0?'+':'' ?><?= (int)$factor['points'] ?></strong><progress max="100" value="<?= max(0,(int)$factor['points']) ?>" aria-label="<?= e($factor['label']) ?>: <?= (int)$factor['points'] ?> points"></progress><small><?= e($factor['detail']) ?></small></div><?php endforeach; ?>
</div>
<h4>Reminder preview</h4>
<?php if($labResult['reminder']): ?><p><strong><?= e(format_datetime($labResult['reminder']['reminder_at'])) ?></strong> · <?= e($labResult['timezone']) ?></p><p class="field-help"><?= e($labResult['reminder']['reason']) ?></p><?php else: ?><p><?= e($labResult['reminder_note']) ?></p><?php endif; ?>
<p class="field-help">This is a new timing experiment; existing scheduled reminders are not changed. The score is a rule-based priority, not a probability of success.</p>
