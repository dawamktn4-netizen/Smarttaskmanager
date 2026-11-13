<?php
declare(strict_types=1);
require_once __DIR__.'/ai.php';

/** A read-only what-if experiment: reuse the real engine instead of a separate browser formula. */
function ai_preview(array $tasks, array $profile, array $input, DateTimeImmutable $now): array
{
    foreach (['task_id','importance','deadline','status','postpone_count'] as $key) {
        if (!isset($input[$key]) || !is_string($input[$key])) throw new InvalidArgumentException('Complete all the experiment fields.');
    }
    if (!preg_match('/^(0|[1-9][0-9]{0,17})$/', $input['task_id']) ||
        !in_array($input['importance'], ['LOW','MEDIUM','HIGH'], true) ||
        !in_array($input['deadline'], ['keep','none','-24','6','24','72','168','336'], true) ||
        !in_array($input['status'], ['PENDING','IN_PROGRESS'], true) ||
        !preg_match('/^[0-3]$/', $input['postpone_count'])) {
        throw new InvalidArgumentException('Choose valid experiment options.');
    }
    $id = (int)$input['task_id'];
    $now = ai_clock($profile, $now);
    $base = null;
    foreach ($tasks as $task) if ((int)$task['task_id'] === $id) $base = $task;
    // Only tasks already fetched for the signed-in account can enter the experiment.
    if ($id !== 0 && $base === null) throw new InvalidArgumentException('This active task is no longer available. Refresh and choose another task.');
    if ($id === 0) $base = ['task_id'=>0,'title'=>'Example assignment','importance'=>'MEDIUM','status'=>'PENDING',
        'postpone_count'=>0,'category_id'=>null,'due_at'=>$now->modify('+72 hours')->format('Y-m-d H:i:s')];
    $candidate = $base;
    $candidate['importance'] = $input['importance'];
    $candidate['status'] = $input['status'];
    $candidate['postpone_count'] = (int)$input['postpone_count'];
    if ($input['deadline'] === 'none') $candidate['due_at'] = null;
    elseif ($input['deadline'] !== 'keep') $candidate['due_at'] = $now->modify($input['deadline'].' hours')->format('Y-m-d H:i:s');
    // A standalone example uses preferences only, not the student's category completion history.
    $experimentProfile = $profile;
    if ($id === 0) $experimentProfile = ai_build_profile([], array_merge($profile, ['preferred_hour'=>$profile['configured_preferred_hour'] ?? 9]), $now);
    $before = ai_evaluate($base, $experimentProfile, $now);
    $after = ai_evaluate($candidate, $experimentProfile, $now);
    $rankBefore = $rankAfter = null;
    if ($id !== 0) {
        foreach (ai_rank_tasks($tasks, $profile, $now) as $index=>$task) if ((int)$task['task_id']===$id) $rankBefore=$index+1;
        $changed = array_map(static fn($task)=>(int)$task['task_id']===$id?$candidate:$task, $tasks);
        foreach (ai_rank_tasks($changed, $profile, $now) as $index=>$task) if ((int)$task['task_id']===$id) $rankAfter=$index+1;
    }
    $reminder = ai_suggest_reminder($candidate, $experimentProfile, $now);
    return ['title'=>$base['title'],'before'=>$before,'after'=>$after,'delta'=>$after['score']-$before['score'],
        'rank_before'=>$rankBefore,'rank_after'=>$rankAfter,'total'=>count($tasks),'due_at'=>$candidate['due_at'],
        'timezone'=>$now->getTimezone()->getName(),'reminder'=>$reminder,'example'=>$id===0,
        'reminder_note'=>!($profile['notifications_enabled']??true)?'Reminders are paused in your preferences.':
            'No suitable time fits before this deadline while respecting quiet hours and the 15-minute minimum.'];
}
