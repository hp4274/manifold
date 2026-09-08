<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$_SESSION = [];
session_destroy();
clear_authenticated();

header('Location: login');
exit;
