<?php
declare(strict_types=1);

session_start();
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../../db.php";

function jsonError(int $code, string $msg): void {
  http_response_code($code);
  echo json_encode(["error" => $msg]);
  exit;
}

if (!isset($_SESSION["user_id"])) {
  jsonError(401, "Non connecté");
}

$userId = (int)$_SESSION["user_id"];
$method = $_SERVER["REQUEST_METHOD"] ?? "GET";

try {
  if ($method === "GET") {
    $stmt = $pdo->prepare("
      SELECT
        c.id,
        c.title,
        c.color,
        c.owner_id,
        CASE WHEN c.owner_id = :owner_uid THEN 1 ELSE 0 END AS is_owner,
        COALESCE(cs.can_edit, 0) AS can_edit
      FROM calendars c
      LEFT JOIN calendar_shares cs
        ON cs.calendar_id = c.id AND cs.user_id = :share_uid
      WHERE c.owner_id = :where_owner_uid
         OR cs.user_id = :where_share_uid
      ORDER BY is_owner DESC, c.title ASC
    ");
    $stmt->execute([
      ":owner_uid" => $userId,
      ":share_uid" => $userId,
      ":where_owner_uid" => $userId,
      ":where_share_uid" => $userId
    ]);

    echo json_encode(["calendars" => $stmt->fetchAll()]);
    exit;
  }

  if ($method === "POST") {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (!is_array($data)) {
      jsonError(400, "JSON invalide");
    }

    $action = (string)($data["action"] ?? "create");
    $calendarId = (int)($data["calendar_id"] ?? 0);
    $title = trim((string)($data["title"] ?? ""));
    $color = trim((string)($data["color"] ?? "#2d9cdb"));

    if (!in_array($action, ["create", "update", "delete"], true)) {
      jsonError(400, "Action invalide");
    }

    if ($action !== "delete") {
      if ($title === "") {
        jsonError(400, "Titre obligatoire");
      }

      if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        jsonError(400, "Couleur invalide");
      }
    }

    if ($action === "create") {
      $stmt = $pdo->prepare("
        INSERT INTO calendars (owner_id, title, color, created_at)
        VALUES (:owner_id, :title, :color, NOW())
      ");
      $stmt->execute([
        ":owner_id" => $userId,
        ":title" => $title,
        ":color" => $color,
      ]);

      echo json_encode([
        "success" => true,
        "calendar_id" => (int)$pdo->lastInsertId()
      ]);
      exit;
    }

    if ($calendarId <= 0) {
      jsonError(400, "calendar_id obligatoire");
    }

    $stmt = $pdo->prepare("
      SELECT id, owner_id
      FROM calendars
      WHERE id = :id
      LIMIT 1
    ");
    $stmt->execute([":id" => $calendarId]);
    $calendar = $stmt->fetch();

    if (!$calendar) {
      jsonError(404, "Calendrier introuvable");
    }

    if ((int)$calendar["owner_id"] !== $userId) {
      jsonError(403, "Seul le propriétaire peut modifier ce calendrier");
    }

    if ($action === "update") {
      $stmt = $pdo->prepare("
        UPDATE calendars
        SET title = :title,
            color = :color
        WHERE id = :id
      ");
      $stmt->execute([
        ":title" => $title,
        ":color" => $color,
        ":id" => $calendarId
      ]);

      echo json_encode([
        "success" => true,
        "calendar_id" => $calendarId
      ]);
      exit;
    }

    if ($action === "delete") {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare("DELETE FROM calendar_shares WHERE calendar_id = :id");
      $stmt->execute([":id" => $calendarId]);

      $stmt = $pdo->prepare("DELETE FROM events WHERE calendar_id = :id");
      $stmt->execute([":id" => $calendarId]);

      $stmt = $pdo->prepare("DELETE FROM calendars WHERE id = :id");
      $stmt->execute([":id" => $calendarId]);

      $stmt = $pdo->prepare("
        SELECT id
        FROM calendars
        WHERE owner_id = :uid
        ORDER BY created_at ASC, id ASC
        LIMIT 1
      ");
      $stmt->execute([":uid" => $userId]);
      $nextOwn = $stmt->fetch();

      if (!$nextOwn) {
        $stmt = $pdo->prepare("
          INSERT INTO calendars (owner_id, title, color, created_at)
          VALUES (:owner_id, 'Calendrier principal', '#2d9cdb', NOW())
        ");
        $stmt->execute([":owner_id" => $userId]);
        $nextCalendarId = (int)$pdo->lastInsertId();
      } else {
        $nextCalendarId = (int)$nextOwn["id"];
      }

      $pdo->commit();

      echo json_encode([
        "success" => true,
        "next_calendar_id" => $nextCalendarId
      ]);
      exit;
    }
  }

  jsonError(405, "Méthode non autorisée");
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  jsonError(500, "Erreur serveur calendars.php : " . $e->getMessage());
}