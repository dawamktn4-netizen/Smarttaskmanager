<section class="panel ai-lab" id="decision-lab" aria-labelledby="lab-title">
    <div class="panel-head"><div><p class="eyebrow">TRY A WHAT-IF</p><h2 id="lab-title">AI decision lab</h2></div><span class="pill">Preview only</span></div>
    <p>What makes a task urgent? Change one input and see the same engine used by your task list explain its decision. Your saved tasks and reminders stay unchanged.</p>
    <div class="lab-grid">
        <form id="ai-lab-form" method="post" action="assistant.php#decision-lab">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <label for="lab-task">Choose a task</label>
            <select name="task_id" id="lab-task">
                <option value="0" data-importance="MEDIUM" data-status="PENDING" data-postponements="0" <?= $labInput['task_id']==='0'?'selected':'' ?>>Try an example assignment</option>
                <?php foreach($ranked as $task): ?><option value="<?= (int)$task['task_id'] ?>" data-importance="<?= e($task['importance']) ?>" data-status="<?= e($task['status']) ?>" data-postponements="<?= min(3,(int)$task['postpone_count']) ?>" <?= $labInput['task_id']===(string)$task['task_id']?'selected':'' ?>><?= e($task['title']) ?></option><?php endforeach; ?>
            </select>
            <div class="lab-controls">
                <div><label for="lab-importance">Importance</label><select name="importance" id="lab-importance"><?php foreach(['LOW'=>'Low','MEDIUM'=>'Medium','HIGH'=>'High'] as $v=>$label): ?><option value="<?= $v ?>" <?= $labInput['importance']===$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
                <div><label for="lab-deadline">Deadline scenario</label><select name="deadline" id="lab-deadline"><?php foreach(['keep'=>'Original deadline','none'=>'No deadline','-24'=>'Overdue by one day','6'=>'Due in 6 hours','24'=>'Due in 24 hours','72'=>'Due in 3 days','168'=>'Due in 7 days','336'=>'Due in 14 days'] as $v=>$label): ?><option value="<?= e((string)$v) ?>" <?= $labInput['deadline']===(string)$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
                <div><label for="lab-status">Progress</label><select name="status" id="lab-status"><option value="PENDING" <?= $labInput['status']==='PENDING'?'selected':'' ?>>Not started</option><option value="IN_PROGRESS" <?= $labInput['status']==='IN_PROGRESS'?'selected':'' ?>>In progress</option></select></div>
                <div><label for="lab-postpone">Postponements</label><select name="postpone_count" id="lab-postpone"><?php for($i=0;$i<=3;$i++): ?><option value="<?= $i ?>" <?= $labInput['postpone_count']===(string)$i?'selected':'' ?>><?= $i===3?'3 or more':$i ?></option><?php endfor; ?></select></div>
            </div>
            <button class="btn primary" type="submit">Explain this change</button>
            <p class="field-help">Tip: try “Due in 14 days”, then “Due in 6 hours”. All times use <?= e($profile['timezone']) ?>. Nothing is automatically scheduled.</p>
            <p id="lab-status-message" role="status"><?= e($labError) ?></p>
        </form>
        <div id="lab-result" aria-label="AI experiment result">
            <?php include __DIR__.'/ai_lab_result.php'; ?>
        </div>
    </div>
</section>
