<?php
// Compatibility route for old bookmarks/links. The consolidated bracket manager
// is admin-brackets.php; keeping this redirect avoids exposing the obsolete
// implementation that used the pre-consolidation workflow.
session_start();
require __DIR__ . '/config.php';
if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: admin-brackets.php' . ($query !== '' ? '?' . $query : ''));
exit;
