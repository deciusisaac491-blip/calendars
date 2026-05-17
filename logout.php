<?php
declare(strict_types=1);
session_start();
session_unset();
session_destroy();
// Redirection vers la page de connexion principale (plus dev_login.php)
header("Location: login.php");
exit;
