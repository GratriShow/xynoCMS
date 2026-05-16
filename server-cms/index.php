<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = current_user();
if ($user === null) {
    redirect('/login.php');
}

redirect('/panel/');
