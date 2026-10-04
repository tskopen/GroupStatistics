<?php
session_start();
if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}
header('Location: admin-brackets.php' . (!empty($_GET['bracket_id']) ? '?bracket_id=' . rawurlencode((string)$_GET['bracket_id']) : ''));
exit;
