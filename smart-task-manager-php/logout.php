<?php
require 'config.php';require_login();require_post();verify_csrf();log_activity($pdo,(int)$_SESSION['user_id'],null,'LOGOUT');$_SESSION=[];
if(ini_get('session.use_cookies')){$params=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$params['path'],$params['domain'],$params['secure'],$params['httponly']);}
session_destroy();redirect('login.php');
