<?php
function getAdminUsernames() {
    $raw=$_ENV['ADMIN_USERS']??getenv('ADMIN_USERS'); if($raw===false||trim((string)$raw)==='')return ['admin'];
    $users=array_values(array_filter(array_map('trim',explode(',',(string)$raw)),fn($u)=>$u!=='')); return $users?:['admin'];
}
function verifyAdminCredentials($username,$password) {
    $username=trim((string)$username); if($username===''||!in_array($username,getAdminUsernames(),true))return false;
    $stored=$_ENV['ADMIN_PASSWORD']??getenv('ADMIN_PASSWORD'); if($stored===false||$stored==='')return false;
    return preg_match('/^\$2[aby]\$|^\$argon2/',$stored)?password_verify((string)$password,$stored):hash_equals((string)$stored,(string)$password);
}
function recordAdminUser($username){getDb()->prepare('INSERT OR IGNORE INTO admin_users(username,created_at) VALUES(?,?)')->execute([$username,date('c')]);}
