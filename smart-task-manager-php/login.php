<?php
require 'config.php';if(!empty($_SESSION['user_id']))redirect('dashboard.php');$error='';$email='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$email=input_string('email');$password=is_string($_POST['password']??null)?$_POST['password']:'';
 // Keep failed attempts from the last five minutes; this limit applies only to this browser session.
 $attempts=$_SESSION['login_attempts']??[];$attempts=array_values(array_filter($attempts,fn($t)=>$t>time()-300));
 if(count($attempts)>=8){$error='Too many attempts in this session. Please wait five minutes and try again.';}
 else{
  // The ? placeholder sends the email as data, so it cannot become part of the SQL command.
  $q=$pdo->prepare("SELECT user_id,full_name,password_hash FROM users WHERE email=? AND account_status='ACTIVE'");$q->execute([$email]);$user=$q->fetch();
  // Verify the stored hash, then replace the session ID so a pre-login ID cannot retain access.
  if($user&&password_verify($password,$user['password_hash'])){session_regenerate_id(true);$_SESSION['user_id']=(int)$user['user_id'];$_SESSION['full_name']=$user['full_name'];$_SESSION['csrf']=bin2hex(random_bytes(32));unset($_SESSION['login_attempts']);$pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE user_id=?')->execute([$user['user_id']]);log_activity($pdo,(int)$user['user_id'],null,'LOGIN');redirect('dashboard.php');}
  $attempts[]=time();$_SESSION['login_attempts']=$attempts;$error='The email or password is incorrect.';
 }
}
$pageTitle='Welcome back';include 'includes/header.php';?>
<main id="main-content" class="auth-wrap"><section class="auth-story"><p class="eyebrow">A LITTLE STRUCTURE. A LOT MORE CLARITY.</p><h1>Your day,<br>with intention.</h1><p>Bring your tasks, priorities and reminders together. Let explainable AI help you find your next step.</p><div class="auth-benefit"><?=icon('sparkles')?><div><strong>Know what matters next</strong><p>Understand the reason behind every priority.</p></div></div><div class="auth-benefit"><?=icon('bell')?><div><strong>Make space to focus</strong><p>Thoughtful reminders that respect your quiet hours.</p></div></div><div class="auth-benefit"><?=icon('target')?><div><strong>Keep the final say</strong><p>Your schedule. Your choices. Your progress.</p></div></div></section>
<section class="auth"><p class="eyebrow">YOUR WORKSPACE IS WAITING</p><h2>Welcome back</h2><p class="muted">Sign in to pick up where you left off.</p><?php if($error):?><div class="flash error" role="alert"><?=e($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><label for="email">Email address</label><input id="email" type="email" name="email" value="<?=e($email)?>" maxlength="190" autocomplete="email" placeholder="you@example.com" required><label for="password">Password</label><input id="password" type="password" name="password" autocomplete="current-password" required><button class="btn primary">Sign in <?=icon('arrow-right')?></button></form><p class="center">New to SmartTask? <a class="link" href="register.php">Create an account</a></p><p class="field-help center">Your task manager works locally. The optional chatbot connects to your selected AI provider with your permission.</p></section></main><?php include 'includes/footer.php';?>
