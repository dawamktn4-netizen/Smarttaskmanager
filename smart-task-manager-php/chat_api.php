<?php
declare(strict_types=1);
require __DIR__.'/config.php';
require_once __DIR__.'/includes/chat.php';
header('Content-Type: application/json; charset=utf-8');
function chat_reply(array $data, int $status=200): void {
    http_response_code($status); echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
if (empty($_SESSION['user_id'])) chat_reply(['error'=>'Please sign in again to use the chatbot.'],401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); chat_reply(['error'=>'Use the chat form.'],405); }
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],input_string('csrf'))) chat_reply(['error'=>'Your session has expired. Refresh this page and try again.'],403);
$uid=(int)$_SESSION['user_id'];
$state=chat_state($uid);
$operation=input_string('operation');
try {
    if ($operation === 'state') chat_reply(chat_public_state($state));
    if ($operation === 'clear') {
        $_SESSION['chat']=['uid'=>$uid,'history'=>[],'pending'=>null,'receipts'=>$state['receipts']];
        chat_reply(chat_public_state($_SESSION['chat']));
    }
    if ($operation === 'cancel') {
        if (!$state['pending'] || !hash_equals($state['pending']['token'],input_string('token'))) chat_reply(['error'=>'This proposal is no longer available. Refresh the conversation.'],409);
        $_SESSION['chat']['pending']=null;
        $_SESSION['chat']['history'][]=['role'=>'assistant','content'=>'Proposal cancelled. No task changes were saved.'];
        $_SESSION['chat']['history']=array_slice($_SESSION['chat']['history'],-24);
        chat_reply(chat_public_state($_SESSION['chat']));
    }
    if ($operation === 'confirm') {
        $token=input_string('token');
        $saved=chat_apply_pending($pdo,$uid,$token,$_SESSION['chat']);
        chat_reply(chat_public_state($_SESSION['chat'])+['saved'=>$saved]);
    }
    if ($operation !== 'message') chat_reply(['error'=>'Unknown chat request.'],400);
    $message=input_string('message');
    if ($message==='' || mb_strlen($message)>2000) chat_reply(['error'=>'Write a message between 1 and 2,000 characters.'],422);
    $config=chat_config();
    if (input_string('provider') !== $config['label']) chat_reply(['error'=>'The AI provider changed. Refresh this page and review the data-sharing choice.'],409);
    if (input_string('consent') !== 'yes') chat_reply(['error'=>'Allow your message and task summary to be sent to the selected AI provider before chatting.'],422);
    if (!chat_ready($config)) chat_reply(['error'=>'The chatbot is not connected yet. Follow the setup steps on this page.'],503);
    $attempts=array_values(array_filter($_SESSION['chat_attempts'] ?? [],static fn($time)=>$time>time()-60));
    if (count($attempts)>=8) chat_reply(['error'=>'Please wait a minute before sending more messages.'],429);
    $attempts[]=time(); $_SESSION['chat_attempts']=$attempts;
    // Keep the session lock: two tabs cannot apply the same pending action concurrently.
    set_time_limit(150);
    $context=chat_context($pdo,$uid);
    $answer=chat_model_request($config,$context,$state['history'],$message);
    if (in_array($answer['action'] ?? '',['start','complete','reschedule'],true) && !in_array($answer['task_id'] ?? null,array_column($context['active_tasks'],'id'),true)) throw new InvalidArgumentException('The AI referenced a task outside this conversation’s workspace snapshot. Please identify an active task.');
    $pending=chat_prepare($pdo,$uid,$answer);
    if ($pending) $answer['message']='Please review the proposed task change below. Nothing has been saved yet.';
    $_SESSION['chat']['history'][]=['role'=>'user','content'=>$message];
    $_SESSION['chat']['history'][]=['role'=>'assistant','content'=>$answer['message']];
    $_SESSION['chat']['history']=array_slice($_SESSION['chat']['history'],-24);
    $_SESSION['chat']['pending']=$pending;
    chat_reply(chat_public_state($_SESSION['chat']));
} catch (InvalidArgumentException $error) {
    chat_reply(['error'=>$error->getMessage()],422);
} catch (PDOException $error) {
    error_log('Chat database error: '.$error->getCode());
    chat_reply(['error'=>'The task change could not be saved. Refresh the conversation before retrying.'],500);
} catch (JsonException $error) {
    chat_reply(['error'=>'The AI returned an unreadable response. Your tasks have not changed. Try again.'],502);
} catch (RuntimeException $error) {
    chat_reply(['error'=>$error->getMessage()],502);
} catch (Throwable $error) {
    error_log('Chat unexpected error: '.get_class($error));
    chat_reply(['error'=>'The chatbot encountered a problem. Refresh the conversation before retrying.'],500);
}
