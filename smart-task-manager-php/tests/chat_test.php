<?php
/** Real database transactions against connection-local TEMPORARY tables only.
 * Existing application records are not read or written after test setup.
 * Run with XAMPP PHP; no API key, external call or model is needed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$suiteCompleted = false;
register_shutdown_function(static function () use (&$suiteCompleted): void {
    if (!$suiteCompleted) {
        fwrite(STDERR, "Chatbot checks did not complete; this run is not a pass.\n");
        exit(1);
    }
});
require __DIR__.'/../config.php';
require __DIR__.'/../includes/chat.php';
$count=0;
function check(bool $condition,string $label): void { global $count; if (!$condition) throw new RuntimeException('FAIL: '.$label); $count++; echo 'PASS: '.$label.PHP_EOL; }
function rejects(callable $fn,string $label): void { try {$fn();} catch (InvalidArgumentException|RuntimeException|JsonException $e) {check(true,$label);return;} throw new Exception('FAIL: '.$label); }
function answer(array $changes=[]): array { return array_replace(['message'=>'Please review this task.','action'=>'none','task_id'=>null,'title'=>null,'description'=>null,'importance'=>null,'due_at'=>null],$changes); }
foreach (['users','app_preferences','categories','tasks','reminders','task_history','user_activity'] as $table) {
    $ddl=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
    $ddl=preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl);
    $ddl=preg_replace('/^\s*CONSTRAINT[^\n]+\n/m', '', $ddl);
    $ddl=preg_replace('/,\n\)/', "\n)", $ddl);
    $pdo->exec($ddl);
}
$pdo->exec("INSERT INTO users(user_id,full_name,email,password_hash,timezone) VALUES(900001,'Chat QA','chat-qa@example.invalid','test-only','Europe/Copenhagen'),(900002,'Other QA','other-qa@example.invalid','test-only','Europe/Copenhagen')");
$uid=900001;
check(chat_validate_answer(answer())['action']==='none','plain answers have no actions');
rejects(fn()=>chat_validate_answer(answer(['action'=>'delete'])),'unknown actions rejected');
rejects(fn()=>chat_validate_answer(answer(['task_id'=>12])),'hidden mutation in plain answer rejected');
rejects(fn()=>chat_validate_answer(answer(['action'=>'create','title'=>'','importance'=>'HIGH'])),'empty create rejected');
rejects(fn()=>chat_validate_answer(answer(['action'=>'create','title'=>'x','importance'=>'URGENT'])),'unknown importance rejected');
rejects(fn()=>chat_validate_answer(answer(['action'=>'create','title'=>'x','importance'=>'HIGH','due_at'=>'2026-02-30T09:00'])),'invalid calendar date rejected');
rejects(fn()=>chat_validate_answer(answer(['action'=>'complete','task_id'=>'12'])),'string task ID rejected');
rejects(fn()=>chat_validate_answer(answer(['action'=>'complete','task_id'=>12,'due_at'=>'2026-12-20T10:00'])),'extra deadline on complete rejected');
rejects(fn()=>chat_validate_answer(answer(['action'=>'reschedule','task_id'=>12])),'reschedule without time rejected');
$fixture=['status'=>'completed','output'=>[['type'=>'function_call','name'=>'respond','arguments'=>json_encode(answer())]]];
check(chat_parse_response($fixture)['action']==='none','Responses API function output parsed');
rejects(fn()=>chat_parse_response(['status'=>'incomplete','output'=>[]]),'truncated model response rejected');
rejects(fn()=>chat_parse_response(['status'=>'completed','output'=>[['type'=>'function_call','name'=>'delete','arguments'=>'{}']]]),'unexpected model tool rejected');
$pending=chat_prepare($pdo,$uid,answer(['action'=>'create','title'=>'QA only <script>alert(1)</script>','description'=>'A note','importance'=>'HIGH','due_at'=>'2026-12-20T10:00']));
check((int)$pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn()===0,'proposal does not change database');
rejects(fn()=>chat_confirm($pdo,900002,$pending),'another user cannot confirm proposal');
$expired=$pending; $expired['expires']=time()-1;
rejects(fn()=>chat_confirm($pdo,$uid,$expired),'expired proposal cannot execute');
$saved=chat_confirm($pdo,$uid,$pending); $id=$saved['task_id'];
$task=$pdo->query('SELECT * FROM tasks WHERE task_id='.$id)->fetch();
check($task['title']==='QA only <script>alert(1)</script>' && $task['status']==='PENDING','confirmed create saves literal title and pending status');
check($task['due_at']==='2026-12-20 10:00:00' && (float)$task['ai_priority_score']>0,'deadline and derived priority saved');
rejects(fn()=>chat_prepare($pdo,900002,answer(['action'=>'complete','task_id'=>$id])),'cross-user task lookup rejected');
$pending=chat_prepare($pdo,$uid,answer(['action'=>'start','task_id'=>$id]));
$pdo->exec("UPDATE tasks SET title='Changed elsewhere' WHERE task_id=$id");
rejects(fn()=>chat_confirm($pdo,$uid,$pending),'stale task proposal rejected');
check(!$pdo->inTransaction(),'failed confirmation rolls back transaction');
$pending=chat_prepare($pdo,$uid,answer(['action'=>'start','task_id'=>$id]));
chat_confirm($pdo,$uid,$pending);
check($pdo->query('SELECT status FROM tasks WHERE task_id='.$id)->fetchColumn()==='IN_PROGRESS','confirmed start changes task');
$pdo->exec("INSERT INTO reminders(task_id,user_id,reminder_at) VALUES($id,$uid,'2026-12-19 09:00:00')");
$pending=chat_prepare($pdo,$uid,answer(['action'=>'reschedule','task_id'=>$id,'due_at'=>'2026-12-21T11:30']));
chat_confirm($pdo,$uid,$pending);
check((int)$pdo->query('SELECT postpone_count FROM tasks WHERE task_id='.$id)->fetchColumn()===1,'later deadline increments postponement count');
check($pdo->query('SELECT reminder_at FROM reminders WHERE task_id='.$id)->fetchColumn()==='2026-12-19 09:00:00','reschedule preserves disclosed reminder time');
$pending=chat_prepare($pdo,$uid,answer(['action'=>'complete','task_id'=>$id]));
chat_confirm($pdo,$uid,$pending);
$task=$pdo->query('SELECT * FROM tasks WHERE task_id='.$id)->fetch();
check($task['status']==='COMPLETED' && $task['completed_at']!==null && (float)$task['ai_priority_score']===0.0,'completion clears priority and sets completion time');
check((int)$pdo->query('SELECT is_enabled FROM reminders WHERE task_id='.$id)->fetchColumn()===0,'completion disables task reminders');
check((int)$pdo->query('SELECT COUNT(*) FROM task_history')->fetchColumn()===4,'each confirmed change recorded in history');
check((int)$pdo->query('SELECT COUNT(*) FROM user_activity')->fetchColumn()===4,'each confirmed change recorded in activity');
rejects(fn()=>chat_prepare($pdo,$uid,answer(['action'=>'start','task_id'=>$id])),'completed task cannot be accidentally restarted');
$pdo->exec("INSERT INTO tasks(user_id,title,description) VALUES(900002,'Other user secret','Private notes'),(900001,'Own active task','Private notes')");
$context=chat_context($pdo,$uid);
check(count($context['active_tasks'])===1 && $context['active_tasks'][0]['title']==='Own active task','workspace snapshot only contains owner active tasks');
check(strpos(json_encode($context),'Private notes')===false && strpos(json_encode($context),'Other user secret')===false,'notes and other user data excluded from context');
$_SESSION['chat']=['uid'=>$uid,'history'=>[['role'=>'user','content'=>'private']],'pending'=>$pending,'receipts'=>[]];
check(chat_state(900002)['history']===[],'chat session reset on account switch');
$proposal=chat_prepare($pdo,$uid,answer(['action'=>'create','title'=>'Only once','importance'=>'LOW']));
$state=['uid'=>$uid,'history'=>[],'pending'=>$proposal,'receipts'=>[]];
$first=chat_apply_pending($pdo,$uid,$proposal['token'],$state);
$second=chat_apply_pending($pdo,$uid,$proposal['token'],$state);
check($first===$second && (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE title='Only once'")->fetchColumn()===1,'duplicate confirmation reuses receipt without creating twice');
rejects(function() use ($pdo,$uid,&$state) {chat_apply_pending($pdo,$uid,'forged-token',$state);},'forged confirmation token rejected');
rejects(function() use ($pdo,$proposal,&$state) {chat_apply_pending($pdo,900002,$proposal['token'],$state);},'receipt cannot be replayed by another user');
$local=['done'=>true,'done_reason'=>'stop','message'=>['content'=>json_encode(answer())]];
check(chat_parse_local_response($local)['action']==='none','local model JSON output parsed');
rejects(fn()=>chat_parse_local_response(['done'=>true,'done_reason'=>'length']),'truncated local model response rejected');
$gemini=['candidates'=>[['finishReason'=>'STOP','content'=>['parts'=>[['text'=>json_encode(answer())]]]]]];
check(chat_parse_gemini_response($gemini)['action']==='none','Gemini structured output parsed');
rejects(fn()=>chat_parse_gemini_response(['candidates'=>[['finishReason'=>'SAFETY']]]),'blocked Gemini answer rejected');
$payload=chat_local_payload(['model'=>'qwen3:1.7b'],'Use the respond function for every reply.', ['active_tasks'=>array_fill(0,30,['id'=>1])],[], 'Plan my day');
check($payload['stream']===false && $payload['think']===false && count($payload['messages'])===2,'local request uses structured non-streaming inference');
check(strpos($payload['messages'][0]['content'],'"active_list_truncated":true')!==false,'local snapshot truncation disclosed');
$local['message']['content']=json_encode(answer(['action'=>'create','title'=>'Date test','importance'=>'HIGH','due_at'=>'2026-10-08 17:00:00']));
check(chat_validate_answer(chat_parse_local_response($local))['due_at']==='2026-10-08 17:00:00','local SQL-style deadline converted without changing time');
$local['message']['content']=json_encode(answer(['title'=>'Unused title','due_at'=>'not a date']));
check(chat_validate_answer(chat_parse_local_response($local))['action']==='none','unused local answer fields cannot cause mutations');
$local['message']['content']=json_encode(answer(['action'=>'create','title'=>'Bad date','importance'=>'HIGH','due_at'=>'next Tuesday']));
rejects(fn()=>chat_validate_answer(chat_parse_local_response($local)),'local parser never guesses natural-language dates');
echo "All $count chatbot checks passed. Temporary tables vanish when this connection closes.\n";

$suiteCompleted = true;
