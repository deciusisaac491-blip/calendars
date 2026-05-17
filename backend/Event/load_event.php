<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| load_event.php
|--------------------------------------------------------------------------
| Charge les événements du calendrier actif.
|
| NOUVEAU :
| - participants internes
| - invités externes
|--------------------------------------------------------------------------
*/

session_start();
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/../../db.php";

/**
 * Réponse JSON d'erreur.
 */
function jsonError(int $code, string $msg): void {
  http_response_code($code);
  echo json_encode(["error" => $msg]);
  exit;
}

/**
 * Convertit une date SQL UTC en ISO UTC avec Z.
 */
function utcSqlToIsoZ(?string $value): ?string {
  if ($value === null || trim($value) === "") {
    return null;
  }

  try {
    $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    return $dt->format('Y-m-d\TH:i:s\Z');
  } catch (Throwable $e) {
    return null;
  }
}

if (!isset($_SESSION["user_id"])) {
  jsonError(401, "Non connecté");
}

$userId = (int)$_SESSION["user_id"];
$calendarId = filter_input(INPUT_GET, "calendar_id", FILTER_VALIDATE_INT);

if (!$calendarId) {
  jsonError(400, "calendar_id invalide");
}

try {
  /*
  |--------------------------------------------------------------------------
  | Vérification accès calendrier
  |--------------------------------------------------------------------------
  */
  $stmt = $pdo->prepare("
    SELECT c.id
    FROM calendars c
    LEFT JOIN calendar_shares cs
      ON cs.calendar_id = c.id AND cs.user_id = :share_uid
    WHERE c.id = :cid
      AND (c.owner_id = :owner_uid OR cs.user_id = :where_uid)
    LIMIT 1
  ");
  $stmt->execute([
    ":cid" => $calendarId,
    ":share_uid" => $userId,
    ":owner_uid" => $userId,
    ":where_uid" => $userId
  ]);

  if (!$stmt->fetch()) {
    jsonError(403, "Accès refusé");
  }

  /*
  |--------------------------------------------------------------------------
  | Chargement des événements
  |--------------------------------------------------------------------------
  */
  $stmt = $pdo->prepare("
    SELECT
      id,
      user_id,
      title,
      description,
      location,
      start,
      end,
      all_day,
      color,
      status,
      priority,
      reminder_minutes,
      updated_at
    FROM events
    WHERE calendar_id = :cid
    ORDER BY start ASC
  ");
  $stmt->execute([":cid" => $calendarId]);
  $rows = $stmt->fetchAll();

  /*
  |--------------------------------------------------------------------------
  | Préparation IDs des événements
  |--------------------------------------------------------------------------
  */
  $eventIds = array_map(
    fn(array $row) => (int)$row["id"],
    $rows
  );

  $internalParticipantsByEvent = [];
  $internalUserIdsByEvent = [];
  $guestEmailsByEvent = [];   // tableau de strings — conservé pour compat. frontend existant
  $guestListByEvent   = [];   // tableau d'objets {email, response} — nouveau

  if (!empty($eventIds)) {
    $placeholders = implode(",", array_fill(0, count($eventIds), "?"));

    /*
    |--------------------------------------------------------------------------
    | Participants internes
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
      SELECT
        ep.event_id,
        ep.user_id,
        ep.response,
        u.full_name,
        u.email
      FROM event_participants ep
      INNER JOIN users u ON u.id = ep.user_id
      WHERE ep.event_id IN ($placeholders)
      ORDER BY ep.event_id ASC, ep.user_id ASC
    ");
    $stmt->execute($eventIds);
    $participantRows = $stmt->fetchAll();

    foreach ($participantRows as $p) {
      $eventId = (int)$p["event_id"];

      $internalParticipantsByEvent[$eventId][] = [
        "user_id"   => (int)$p["user_id"],
        "full_name" => $p["full_name"] !== null ? (string)$p["full_name"] : null,
        "email"     => $p["email"] !== null ? (string)$p["email"] : null,
        /*
        | response : 'pending' (défaut), 'accepted' ou 'declined'.
        | Null si la colonne n'existe pas encore en base (migration pas encore jouée).
        | Le frontend affiche le badge correspondant dans le formulaire d'édition.
        */
        "response"  => $p["response"] !== null ? (string)$p["response"] : 'pending',
      ];

      $internalUserIdsByEvent[$eventId][] = (int)$p["user_id"];
    }

    /*
    |--------------------------------------------------------------------------
    | Invités externes
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
      SELECT
        event_id,
        guest_email,
        response
      FROM event_guests
      WHERE event_id IN ($placeholders)
      ORDER BY event_id ASC, guest_email ASC
    ");
    $stmt->execute($eventIds);
    $guestRows = $stmt->fetchAll();

    foreach ($guestRows as $g) {
      $eventId = (int)$g["event_id"];
      $email   = (string)$g["guest_email"];

      /*
      | guest_emails : tableau de strings conservé tel quel pour le code frontend
      | existant qui l'utilise pour pré-remplir le champ de saisie des invités.
      */
      $guestEmailsByEvent[$eventId][] = $email;

      /*
      | guest_list : nouveau tableau d'objets exposant aussi la réponse.
      | Utilisé par renderParticipantResponses() dans index.js pour afficher
      | les badges de statut sans casser l'existant.
      */
      $guestListByEvent[$eventId][] = [
        "email"    => $email,
        "response" => $g["response"] !== null ? (string)$g["response"] : 'pending',
      ];
    }
  }

  /*
  |--------------------------------------------------------------------------
  | Construction réponse FullCalendar
  |--------------------------------------------------------------------------
  */
  $events = [];
  foreach ($rows as $r) {
    $eventId = (int)$r["id"];
    $color = $r["color"] ?: "#2d9cdb";

    $prefixStatus = match ($r["status"]) {
      "pending" => "⏳ ",
      "cancelled" => "❌ ",
      default => ""
    };

    $prefixPriority = $r["priority"] === "high" ? "🔴 " : "";
    $displayTitle = $prefixStatus . $prefixPriority . $r["title"];

    if ((int)$r["all_day"] === 1) {
      $startDate = substr((string)$r["start"], 0, 10);
      $endBase = substr((string)($r["end"] ?: $r["start"]), 0, 10);

      $endExclusive = null;
      try {
        $endExclusive = (new DateTimeImmutable($endBase, new DateTimeZone('UTC')))
          ->modify('+1 day')
          ->format('Y-m-d');
      } catch (Throwable $e) {
        $endExclusive = null;
      }

      $startOut = $startDate;
      $endOut = $endExclusive;
    } else {
      $startOut = utcSqlToIsoZ($r["start"]);
      $endOut = utcSqlToIsoZ($r["end"]);
    }

    $events[] = [
      "id" => $eventId,
      "title" => $displayTitle,
      "start" => $startOut,
      "end" => $endOut,
      "allDay" => ((int)$r["all_day"] === 1),
      "backgroundColor" => $color,
      "borderColor" => $color,
      "extendedProps" => [
        "raw_title" => $r["title"],
        "description" => $r["description"],
        "location" => $r["location"],
        "creator_user_id" => (int)$r["user_id"],
        "client_updated_at" => $r["updated_at"],
        "color" => $color,
        "status" => $r["status"],
        "priority" => $r["priority"],
        "reminder_minutes" => $r["reminder_minutes"] !== null ? (int)$r["reminder_minutes"] : null,

        // Participants / invités
        "internal_participants" => $internalParticipantsByEvent[$eventId] ?? [],
        "internal_user_ids"     => $internalUserIdsByEvent[$eventId] ?? [],

        /*
        | guest_emails : tableau de strings ["a@b.com", "c@d.com"]
        | Conservé inchangé — utilisé par le code JS existant pour pré-remplir
        | le champ de saisie des invités externes dans le formulaire d'édition.
        */
        "guest_emails" => $guestEmailsByEvent[$eventId] ?? [],

        /*
        | guest_list : tableau d'objets [{email, response}, ...]
        | Nouveau — utilisé par renderParticipantResponses() dans index.js
        | pour afficher les badges accepted/pending/declined sans modifier
        | le code JS existant qui consomme guest_emails.
        */
        "guest_list" => $guestListByEvent[$eventId] ?? []
      ]
    ];
  }

  echo json_encode(["events" => $events]);
} catch (Throwable $e) {
  jsonError(500, "Erreur load_event : " . $e->getMessage());
}