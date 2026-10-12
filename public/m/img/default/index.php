<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . '/src/public_api_runtime.php';

run_public_api('mobile', basename(__DIR__));
