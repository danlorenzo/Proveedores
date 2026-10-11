<?php
declare(strict_types=1);
$query = http_build_query($_GET, '', '&', PHP_QUERY_RFC3986);
header('Location: frontend/login.php' . ($query !== '' ? '?' . $query : ''));
exit;
