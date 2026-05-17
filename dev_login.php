<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . "/db.php";

// ✅ Crée l'utilisateur 1 si absent
$pdo->exec("
  INSERT INTO users (id, email, password_hash, created_at)
  VALUES (1, 'test@local', 'x', NOW())
  ON DUPLICATE KEY UPDATE email = email
");

// ✅ Crée le calendrier 1 si absent (owner = user 1)
$pdo->exec("
  INSERT INTO calendars (id, owner_id, title, color, created_at)
  VALUES (1, 1, 'Calendrier principal', '#2d9cdb', NOW())
  ON DUPLICATE KEY UPDATE title = title
");

// ✅ Session user
$_SESSION["user_id"] = 1;

header("Location: index.php");
exit;
