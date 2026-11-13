<?php
/** Opt-in live local-model checks using synthetic tasks only; no task writes. */
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
$suiteCompleted = false;
register_shutdown_function(static function () use (&$suiteCompleted): void {
    if (!$suiteCompleted) {
        fwrite(STDERR, "Chatbot checks did not complete; this run is not a pass.\n");
        exit(1);
    }
});
require __DIR__.'/../config.php';
require __DIR__.'/../includes/chat.php';
$config=chat_config();
if ($config['provider']!=='ollama' || !chat_ready($config)) throw new RuntimeException('Start Ollama and download the configured local model first.');
$context=['local_now'=>'2026-10-07 12:00:00','timezone'=>'Europe/Copenhagen','active_list_truncated'=>false,'totals'=>[['status'=>'PENDING','total'=>2]],'active_tasks'=>[
 ['id'=>101,'title'=>'Prepare presentation','due_at'=>'2026-10-08 17:00:00','importance'=>'HIGH','status'=>'PENDING','rule_based_priority'=>75,'priority_reason'=>'High importance and an approaching deadline.'],
 ['id'=>102,'title'=>'Read chapter two','due_at'=>null,'importance'=>'LOW','status'=>'PENDING','rule_based_priority'=>10,'priority_reason'=>'Low importance and no deadline.']]];
$cases=[
 ['What should I focus on today?','none',null],
 ['Create a high importance task called Submit report, due tomorrow at 5 pm.','create',null],
 ['Start Prepare presentation.','start',101],
 ['Mark Prepare presentation complete.','complete',101],
 ['Move Prepare presentation to 10 October 2026 at 3 pm.','reschedule',101],
];
foreach ($cases as [$prompt,$action,$id]) {
 $started=microtime(true);
 $raw=chat_model_request($config,$context,[],$prompt);
 try {$reply=chat_validate_answer($raw);} catch (Throwable $error) {echo json_encode($raw).PHP_EOL; throw $error;}
 if ($reply['action']!==$action || $reply['task_id']!==$id) throw new RuntimeException('Unexpected proposal: '.json_encode($reply));
 if ($action==='none' && stripos($reply['message'],'Prepare presentation')===false) throw new RuntimeException('Planning answer did not identify the relevant task.');
 if ($action==='create' && ($reply['due_at']!=='2026-10-08 17:00:00' || $reply['importance']!=='HIGH')) throw new RuntimeException('Incorrect creation fields.');
 if ($action==='reschedule' && $reply['due_at']!=='2026-10-10 15:00:00') throw new RuntimeException('Incorrect reschedule date.');
 echo 'PASS live '.$action.' ('.round(microtime(true)-$started,1).' seconds): '.$reply['message'].PHP_EOL;
}

$suiteCompleted = true;
