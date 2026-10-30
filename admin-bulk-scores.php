<?php
session_start();
require __DIR__ . '/config.php';
if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}
header('Location: admin-score-management.php?notice=legacy');
exit;
