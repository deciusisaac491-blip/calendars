<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Connexion PDO à la base calendars_db
|--------------------------------------------------------------------------
| Les paramètres de connexion sont définis dans config.php (non versionné).
| Créez config.php à partir de config.example.php avant de lancer le projet.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    die("Connexion DB impossible : " . $e->getMessage());
}
