<?php
// Create and edit share this handler so both use the same server-side validation and saving rules.
require_once __DIR__.'/../config.php';require_login();$uid=(int)$_SESSION['user_id'];$id=$isEdit?(int)input_string('id',$_GET):0;
$task=['title'=>'','description'=>'','category_id'=>'','due_at'=>'','importance'=>'MEDIUM','status'=>'PENDING','postpone_count'=>0,'completed_at'=>null];
// Check both IDs: knowing a task's ID must not let someone view or edit another user's task.
if($isEdit){$q=$pdo->prepare('SELECT * FROM tasks WHERE task_id=? AND user_id=?');$q->execute([$id,$uid]);$task=$q->fetch();if(!$task){http_response_code(404);$pageTitle='Task not found';include __DIR__.'/header.php';echo '<main class="container"><section class="panel"><h1>Task not found</h1><a class="btn primary" href="tasks.php">Back to tasks</a></section></main>';include __DIR__.'/footer.php';exit;}}
$q=$pdo->prepare('SELECT category_id,category_name FROM categories WHERE user_id IS NULL OR user_id=? ORDER BY category_name');$q->execute([$uid]);$categories=$q->fetchAll();$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$old=$task;
 foreach(['title','description','category_id','due_at','importance','status'] as $field)$task[$field]=input_string($field);
 if($task['title']===''||mb_strlen($task['title'])>180)$errors[]='Use a title between 1 and 180 characters.';
 if(mb_strlen($task['description'])>10000)$errors[]='Keep notes under 10,000 characters.';
 if(!in_array($task['importance'],['LOW','MEDIUM','HIGH'],true))$errors[]='Choose a valid importance.';
 if(!in_array($task['status'],['PENDING','IN_PROGRESS','COMPLETED','CANCELLED'],true))$errors[]='Choose a valid status.';
 // Accept only a shared category or one owned by this user, even if the submitted form was altered.
 if($task['category_id']!==''&&!in_array($task['category_id'],array_map('strval',array_column($categories,'category_id')),true))$errors[]='Choose one of your categories.';
 try{$task['due_at']=parse_datetime($task['due_at']);}catch(InvalidArgumentException $ex){$errors[]=$ex->getMessage();}
 if(!$errors){
  // The transaction saves the task, history and reminder changes together; any failure rolls them all back.
  $ai=ai_evaluate($task,ai_profile($pdo,$uid));$completed=$task['status']==='COMPLETED'?($old['completed_at']??date('Y-m-d H:i:s')):null;$pdo->beginTransaction();
  try{
   $values=[$task['category_id']?:null,$task['title'],$task['description'],$task['due_at'],$task['importance'],$task['status'],$ai['score'],$ai['level'],$ai['reason'],$completed];
   if($isEdit){$pdo->prepare('UPDATE tasks SET category_id=?,title=?,description=?,due_at=?,importance=?,status=?,ai_priority_score=?,ai_priority_level=?,ai_reason=?,completed_at=?,ai_last_calculated_at=NOW() WHERE task_id=? AND user_id=?')->execute(array_merge($values,[$id,$uid]));log_history($pdo,$id,$uid,'UPDATED','Task details saved.');log_activity($pdo,$uid,$id,'TASK_EDITED','Task details saved.');}
   else{$pdo->prepare('INSERT INTO tasks(category_id,title,description,due_at,importance,status,ai_priority_score,ai_priority_level,ai_reason,completed_at,ai_last_calculated_at,user_id) VALUES(?,?,?,?,?,?,?,?,?,?,NOW(),?)')->execute(array_merge($values,[$uid]));$id=(int)$pdo->lastInsertId();log_history($pdo,$id,$uid,'CREATED','Task created.');log_activity($pdo,$uid,$id,'TASK_CREATED','Task created.');}
   if($task['status']!==$old['status']){log_history($pdo,$id,$uid,$task['status']==='COMPLETED'?'COMPLETED':'STATUS_CHANGED',status_label($old['status']).' → '.status_label($task['status']));if($task['status']==='COMPLETED')log_activity($pdo,$uid,$id,'TASK_COMPLETED','Completed from task editor.');}
   if(in_array($task['status'],['COMPLETED','CANCELLED'],true))$pdo->prepare('UPDATE reminders SET is_enabled=0 WHERE task_id=? AND user_id=?')->execute([$id,$uid]);
   $pdo->commit();flash('success',$isEdit?'Your changes are saved.':'Task created. Your focus list is ready.');redirect('tasks.php');
  }catch(Throwable $ex){$pdo->rollBack();error_log('Task save failed: '.$ex->getMessage());$errors[]='We could not save this task. Please try again.';}
 }
}
$pageTitle=$isEdit?'Edit task':'Create task';include __DIR__.'/header.php';include __DIR__.'/task_form.php';include __DIR__.'/footer.php';
