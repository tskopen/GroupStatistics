<?php
function getAdminUsernames() {
    $raw=$_ENV['ADMIN_USERS']??getenv('ADMIN_USERS');
    $users=($raw!==false&&trim((string)$raw)!=='')?array_values(array_filter(array_map('trim',explode(',',(string)$raw)),fn($u)=>$u!=='')):['admin'];
    try {
        $dbUsers=getDb()->query('SELECT username FROM admin_users')->fetchAll(PDO::FETCH_COLUMN);
        $users=array_values(array_unique(array_merge($users,$dbUsers)));
    } catch (Throwable $e) {}
    return $users?:['admin'];
}
function verifyAdminCredentials($username,$password) {
    $username=trim((string)$username); if($username==='')return false;
    try {
        $stmt=getDb()->prepare('SELECT password_hash FROM admin_users WHERE username=? LIMIT 1');
        $stmt->execute([$username]); $hash=$stmt->fetchColumn();
        if($hash!==false && $hash!==null && $hash!=='') return password_verify((string)$password,(string)$hash);
    } catch (Throwable $e) {}
    if(!in_array($username,getAdminUsernames(),true))return false;
    $stored=$_ENV['ADMIN_PASSWORD']??getenv('ADMIN_PASSWORD'); if($stored===false||$stored==='')return false;
    return preg_match('/^\\$2[aby]\\$|^\\$argon2/',$stored)?password_verify((string)$password,$stored):hash_equals((string)$stored,(string)$password);
}
function recordAdminUser($username){getDb()->prepare('INSERT OR IGNORE INTO admin_users(username,created_at) VALUES(?,?)')->execute([$username,date('c')]);}
