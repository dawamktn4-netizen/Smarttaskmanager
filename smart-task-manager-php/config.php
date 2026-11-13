<?php
declare(strict_types=1);
// Shared setup: pages use the same database connection, session and validation helpers.
date_default_timezone_set('Europe/Copenhagen');
if (session_status() !== PHP_SESSION_ACTIVE) {
    // HttpOnly hides the session cookie from JavaScript; SameSite limits cross-site use.
    // The Secure flag is enabled on HTTPS so that cookie is sent only over an encrypted connection.
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off']);
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('Cache-Control: no-store');
// Escape text when inserting it into HTML so task titles cannot execute as browser code (XSS).
function e(?string $v):string{return htmlspecialchars($v??'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function input_string(string $key,?array $source=null):string{$source=$source??$_POST;return isset($source[$key])&&is_string($source[$key])?trim($source[$key]):'';}
function redirect(string $path):void{header('Location: '.$path);exit;}
function flash(string $type,string $message):void{$_SESSION['flash']=[$type,$message];}
// Forms carry this unpredictable session token; verify_csrf rejects changes without a matching token.
function csrf():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function require_login():void{if(empty($_SESSION['user_id']))redirect('login.php');}
function require_post():void{if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit('Use the form to perform this action.');}}
// POST alone is not CSRF protection: also compare the submitted token using hash_equals.
function verify_csrf():void{
 if($_SERVER['REQUEST_METHOD']!=='POST'||!isset($_POST['csrf'])||!is_string($_POST['csrf'])||empty($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],$_POST['csrf'])){http_response_code(403);exit('This request has expired. Go back, refresh the page, and try again.');}
}
function parse_datetime(string $value):?string{
 // Reject invalid dates instead of allowing PHP to silently roll them into a different day.
 if($value==='')return null;
 foreach(['!Y-m-d\TH:i','!Y-m-d H:i:s','!Y-m-d\TH:i:s'] as $format){
  $date=DateTimeImmutable::createFromFormat($format,$value);$errors=DateTimeImmutable::getLastErrors();
  if($date&&(!$errors||(!$errors['warning_count']&&!$errors['error_count']))&&$date->format(substr($format,1))===$value&&(int)$date->format('Y')>=1000)return $date->format('Y-m-d H:i:s');
 }
 throw new InvalidArgumentException('Enter a valid date and time.');
}
function format_datetime(?string $date):string{return $date?date('d M Y, H:i',strtotime($date)):'No deadline';}
function status_label(string $status):string{return ucwords(strtolower(str_replace('_',' ',$status)));}
function log_history(PDO $p,int $task,int $uid,string $action,string $details=''):void{$p->prepare('INSERT INTO task_history(task_id,user_id,action_type,details) VALUES(?,?,?,?)')->execute([$task,$uid,$action,$details]);}
function log_activity(PDO $p,int $uid,?int $task,string $type,string $details=''):void{$p->prepare('INSERT INTO user_activity(user_id,task_id,activity_type,activity_details) VALUES(?,?,?,?)')->execute([$uid,$task,$type,$details]);}
function user_preferences(PDO $p,int $uid):array{
 $q=$p->prepare('SELECT u.timezone,p.quiet_start,p.quiet_end,p.preferred_hour,p.notifications_enabled FROM users u LEFT JOIN app_preferences p ON p.user_id=u.user_id WHERE u.user_id=?');$q->execute([$uid]);$r=$q->fetch()?:[];
 return ['timezone'=>$r['timezone']??'Europe/Copenhagen','quiet_start'=>substr($r['quiet_start']??'22:00',0,5),'quiet_end'=>substr($r['quiet_end']??'08:00',0,5),'preferred_hour'=>(int)($r['preferred_hour']??9),'notifications_enabled'=>(bool)($r['notifications_enabled']??true)];
}
try{
 // PDO uses native prepared statements: query placeholders keep user values separate from SQL syntax.
 $pdo=new PDO('mysql:host='.(getenv('DB_HOST')?:'localhost').';dbname='.(getenv('DB_NAME')?:'smart_task_manager').';charset=utf8mb4',getenv('DB_USER')?:'root',getenv('DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 // Additive and repeatable: never drop or replace the user's existing data.
 $pdo->exec("CREATE TABLE IF NOT EXISTS app_preferences (user_id BIGINT UNSIGNED PRIMARY KEY,quiet_start TIME NOT NULL DEFAULT '22:00:00',quiet_end TIME NOT NULL DEFAULT '08:00:00',preferred_hour TINYINT UNSIGNED NOT NULL DEFAULT 9,notifications_enabled BOOLEAN NOT NULL DEFAULT TRUE,CONSTRAINT fk_preferences_user FOREIGN KEY(user_id) REFERENCES users(user_id) ON DELETE CASCADE) ENGINE=InnoDB");
 if(!empty($_SESSION['user_id'])){
  $q=$pdo->prepare("SELECT full_name,timezone FROM users WHERE user_id=? AND account_status='ACTIVE'");$q->execute([(int)$_SESSION['user_id']]);$account=$q->fetch();
  if(!$account){unset($_SESSION['user_id'],$_SESSION['full_name']);}else{$_SESSION['full_name']=$account['full_name'];if(in_array($account['timezone'],DateTimeZone::listIdentifiers(),true))date_default_timezone_set($account['timezone']);}
 }
 // Keep database NOW()/CURDATE() aligned with PHP's account clock; DATETIME values store local times.
 $pdo->exec('SET time_zone = '.$pdo->quote(date('P')));
}catch(PDOException $exception){
 error_log('SmartTask database unavailable: '.$exception->getCode());http_response_code(503);
 exit('<!doctype html><html lang="en"><meta charset="utf-8"><title>SmartTask setup</title><link rel="stylesheet" href="assets/style.css"><main class="container"><section class="panel"><h1>Let’s connect your workspace</h1><p>Start Apache and MySQL in XAMPP, then import database/smart_task_manager.sql using phpMyAdmin.</p><p>If your database credentials differ, set DB_HOST, DB_NAME, DB_USER and DB_PASSWORD in the server environment.</p><a class="btn primary" href="index.php">Try again</a></section></main></html>');
}
require_once __DIR__.'/includes/ai.php';
