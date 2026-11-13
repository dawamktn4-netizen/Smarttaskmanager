<?php
require 'config.php';require_login();require_post();verify_csrf();$uid=(int)$_SESSION['user_id'];$id=(int)input_string('id');$action=input_string('action');
$return=in_array(input_string('return_to'),['dashboard.php','tasks.php','assistant.php'],true)?input_string('return_to'):'tasks.php';
// Keep the task-list view after an action, accepting only known local filter values.
if($return==='tasks.php'){
 $filters=[];$returnStatus=input_string('return_status');$returnSort=input_string('return_sort');
 if(in_array($returnStatus,['ACTIVE','ALL','PENDING','IN_PROGRESS','OVERDUE','COMPLETED','CANCELLED'],true))$filters['status']=$returnStatus;
 if(input_string('return_q')!=='')$filters['q']=mb_substr(input_string('return_q'),0,180);
 $returnCategory=filter_var(input_string('return_category'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 if($returnCategory)$filters['category']=$returnCategory;
 if(in_array($returnSort,['ai','due','newest'],true))$filters['sort']=$returnSort;
 if($filters)$return.='?'.http_build_query($filters);
}
if(!in_array($action,['complete','postpone','delete','start','reopen'],true)){http_response_code(400);exit('Unknown task action.');}
$pdo->beginTransaction();
try{
 // Lock the owned task until commit so task actions read its current state before changing it.
 $q=$pdo->prepare('SELECT * FROM tasks WHERE task_id=? AND user_id=? FOR UPDATE');$q->execute([$id,$uid]);$task=$q->fetch();
 if(!$task){$pdo->rollBack();http_response_code(404);exit('Task not found.');}
 $active=in_array($task['status'],['PENDING','IN_PROGRESS'],true);
 if($action==='delete'){$pdo->prepare('DELETE FROM tasks WHERE task_id=? AND user_id=?')->execute([$id,$uid]);$message='Task deleted.';}
 // Completing a task removes its priority and disables reminders in the same transaction.
 elseif($action==='complete'&&$active){$pdo->prepare("UPDATE tasks SET status='COMPLETED',completed_at=NOW(),ai_priority_score=0,ai_priority_level='LOW',ai_reason='Completed tasks do not need prioritisation.' WHERE task_id=? AND user_id=?")->execute([$id,$uid]);$pdo->prepare('UPDATE reminders SET is_enabled=0 WHERE task_id=? AND user_id=?')->execute([$id,$uid]);log_history($pdo,$id,$uid,'COMPLETED','Task marked complete.');log_activity($pdo,$uid,$id,'TASK_COMPLETED','Task marked complete.');$message='Nicely done. Task completed.';}
 // Move the deadline one local calendar day and count the delay; existing reminder times stay unchanged.
 elseif($action==='postpone'&&$active){$new=(new DateTimeImmutable($task['due_at']?:'now'))->modify('+1 day')->format('Y-m-d H:i:s');$pdo->prepare('UPDATE tasks SET due_at=?,postpone_count=postpone_count+1 WHERE task_id=? AND user_id=?')->execute([$new,$id,$uid]);log_history($pdo,$id,$uid,'POSTPONED','Deadline moved from '.($task['due_at']?:'no deadline').' to '.$new.'. Existing reminders are unchanged.');log_activity($pdo,$uid,$id,'TASK_POSTPONED','Deadline postponed by one day.');$message='Deadline moved by one day. Review any existing reminders.';}
 elseif($action==='start'&&$active){$pdo->prepare("UPDATE tasks SET status='IN_PROGRESS' WHERE task_id=? AND user_id=?")->execute([$id,$uid]);log_history($pdo,$id,$uid,'STATUS_CHANGED','Task started.');$message='Task is now in progress.';}
 // Reopening clears the completion date; disabled reminders stay disabled until the user schedules one.
 elseif($action==='reopen'&&!$active){$pdo->prepare("UPDATE tasks SET status='PENDING',completed_at=NULL WHERE task_id=? AND user_id=?")->execute([$id,$uid]);log_history($pdo,$id,$uid,'STATUS_CHANGED','Task reopened. Schedule a new reminder if needed.');$message='Task reopened. You can schedule a new reminder.';}
 else{$message='This task is already up to date.';}
 $pdo->commit();flash('success',$message);
 // Derived scores are refreshed by the destination page; action success is already committed.
}catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();error_log('Task action failed: '.$ex->getMessage());flash('error','We could not update that task. Please try again.');}
redirect($return);
