<?php
// Legacy compatibility route. Bracket creation is now handled by admin-brackets.php.
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: admin-brackets.php' . ($query !== '' ? '?' . $query : ''));
exit;
