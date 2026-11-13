<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/ai_preview.php';
$checks=0;
function expect_preview(bool $condition,string $message):void {global $checks;$checks++;if(!$condition)throw new RuntimeException($message);}
$now=new DateTimeImmutable('2026-09-30 10:00:00',new DateTimeZone('Europe/London'));
$profile=ai_build_profile([],['timezone'=>'Europe/London','preferred_hour'=>9,'quiet_start'=>'22:00','quiet_end'=>'08:00'], $now);
$input=['task_id'=>'0','importance'=>'MEDIUM','deadline'=>'keep','status'=>'PENDING','postpone_count'=>'0'];
$r=ai_preview([],$profile,$input,$now);
expect_preview($r['before']['score']===47.0 && $r['after']['score']===47.0,'Example starts with a three-day medium-importance deadline.');
$r=ai_preview([],$profile,array_replace($input,['importance'=>'HIGH','deadline'=>'6']),$now);
expect_preview($r['after']['score']===75.0 && $r['delta']===28.0,'Urgency and importance changes use the real score.');
expect_preview($r['reminder']['reminder_at']<'2026-09-30 16:00:00','Reminder fits before the preview deadline.');
$tasks=[['task_id'=>1,'category_id'=>1,'title'=>'Essay','due_at'=>'2026-10-14 10:00:00','importance'=>'LOW','status'=>'PENDING','postpone_count'=>0],
 ['task_id'=>2,'category_id'=>1,'title'=>'Revision','due_at'=>'2026-10-01 10:00:00','importance'=>'HIGH','status'=>'PENDING','postpone_count'=>0]];
$copy=$tasks;
$r=ai_preview($tasks,$profile,array_replace($input,['task_id'=>'1','importance'=>'HIGH','deadline'=>'-24','postpone_count'=>'3','status'=>'IN_PROGRESS']),$now);
expect_preview($r['rank_before']===2 && $r['rank_after']===1,'One-task experiment changes its position among unchanged peers.');
expect_preview($r['after']['score']===100.0,'Combined contributions respect the score cap.');
expect_preview($copy===$tasks,'Preview leaves its input records unchanged.');
foreach([['task_id'=>'999'],['task_id'=>'-1'],['importance'=>'URGENT'],['deadline'=>'tomorrow'],['status'=>'COMPLETED'],['postpone_count'=>'4'],['deadline'=>[]]] as $invalid){
 $rejected=false;try{ai_preview($tasks,$profile,array_replace($input,$invalid),$now);}catch(InvalidArgumentException $e){$rejected=true;}
 expect_preview($rejected,'Reject unavailable task or invalid input: '.json_encode($invalid));
}
$r=ai_preview([],$profile,array_replace($input,['deadline'=>'none']),$now);
expect_preview($r['due_at']===null&&$r['after']['score']===22.0,'No deadline removes urgency.');
$r=ai_preview([],array_replace($profile,['notifications_enabled'=>false]),$input,$now);
expect_preview($r['reminder']===null&&strpos($r['reminder_note'],'paused')!==false,'Disabled reminders are explained.');
$historyProfile=array_replace($profile,['preferred_hour'=>16,'configured_preferred_hour'=>9,'behavior_source'=>'history','completion_count'=>5,'category_completion_counts'=>['none'=>5],'category_delay_rates'=>['none'=>1]]);
$r=ai_preview([],$historyProfile,$input,$now);
expect_preview($r['after']['score']===47.0&&strpos($r['reminder']['reason'],'preferred hour (09:00)')!==false,'Example is separate from actual completion history.');
echo "Passed {$checks} AI preview checks.\n";
