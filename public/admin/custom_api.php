<?php
declare(strict_types=1);
$requestedPage = ($_GET['view'] ?? '') === 'docs' ? 'docs' : 'api';
require __DIR__ . '/index.php';
