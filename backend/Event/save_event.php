<?php
declare(strict_types=1);

session_start();
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../../db.php";
require_once __DIR__ . "/../mail/send_mail.php";

/**
 * Envoie une erreur JSON propre puis stoppe l'exécution.
 */
function jsonError(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(["error" => $msg]);
    exit;
}

/**
 * Vérifie si l'utilisateur a le droit de modifier le calendrier :
 * - soit il est propriétaire
 * - soit il a un partage avec droit d'édition
 */
function canEditCalendar(PDO $pdo, int $calendarId, int $userId): bool
{
    $stmt = $pdo->prepare("SELECT owner_id FROM calendars WHERE id = :id LIMIT 1");
    $stmt->execute([":id" => $calendarId]);
    $cal = $stmt->fetch();

    if ($cal && (int)$cal["owner_id"] === $userId) {
        return true;
    }

    $stmt = $pdo->prepare("SELECT can_edit FROM calendar_shares WHERE calendar_id = :cid AND user_id = :uid LIMIT 1");
    $stmt->execute([":cid" => $calendarId, ":uid" => $userId]);
    $share = $stmt->fetch();

    return $share && (int)$share["can_edit"] === 1;
}

/**
 * Convertit une date/heure reçue en UTC au format SQL.
 * Retourne null si la valeur est vide ou invalide.
 */
function normalizeUtcDateTimeString(?string $value): ?string
{
    if ($value === null || trim($value) === "") {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($value);
        $utc = $dt->setTimezone(new DateTimeZone('UTC'));
        return $utc->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Vérifie un format de date simple YYYY-MM-DD pour les évènements "toute la journée".
 */
function normalizeAllDayDate(?string $value): ?string
{
    if ($value === null || trim($value) === "") {
        return null;
    }

    $value = trim($value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
}

/**
 * Nettoie la liste des IDs utilisateurs internes.
 * - garde uniquement les IDs > 0
 * - supprime les doublons
 */
function normalizeInternalUserIds(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $ids = [];
    foreach ($value as $item) {
        $id = (int)$item;
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * Nettoie la liste des emails invités externes.
 * - passe en minuscule
 * - valide le format email
 * - supprime les doublons
 */
function normalizeGuestEmails(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $emails = [];
    foreach ($value as $item) {
        $email = strtolower(trim((string)$item));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }

    return array_values(array_unique($emails));
}

/**
 * Vérifie que tous les utilisateurs internes existent bien dans la base.
 */
function ensureUsersExist(PDO $pdo, array $userIds): void
{
    if (empty($userIds)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders)");
    $stmt->execute($userIds);

    $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    sort($found);
    $wanted = $userIds;
    sort($wanted);

    if ($found !== $wanted) {
        jsonError(400, 'Un ou plusieurs participants internes sont invalides');
    }
}

/**
 * Sauvegarde les participants internes et invités externes d'un évènement.
 * On supprime d'abord l'existant, puis on réinsère les données actuelles.
 *
 * Pour chaque participant/invité, on génère un token unique de 64 caractères
 * (bin2hex(random_bytes(32)) = 256 bits d'entropie, impossible à deviner).
 * Ce token sera utilisé pour construire les liens Accepter / Refuser dans le mail.
 *
 * @return array{
 *   participants: array<int, string>,
 *   guests: array<string, string>
 * }
 * Exemple : [
 *   'participants' => [42 => 'a3f8...', 7 => 'c91e...'],
 *   'guests'       => ['foo@bar.com' => 'd42a...']
 * ]
 */
function saveParticipantsAndGuests(PDO $pdo, int $eventId, array $internalUserIds, array $guestEmails): array
{
    $stmt = $pdo->prepare("DELETE FROM event_participants WHERE event_id = :event_id");
    $stmt->execute([':event_id' => $eventId]);

    $stmt = $pdo->prepare("DELETE FROM event_guests WHERE event_id = :event_id");
    $stmt->execute([':event_id' => $eventId]);

    /*
    | Tableaux retournés : userId => token  et  email => token.
    | Ils seront transmis à buildMailRecipients() pour construire les URLs.
    */
    $participantTokens = [];
    $guestTokens       = [];

    if (!empty($internalUserIds)) {
        /**
         * IMPORTANT :
         * Ici on utilise bien la vraie colonne de la base :
         * role_name
         * et non role
         */
        $stmt = $pdo->prepare("
            INSERT INTO event_participants (event_id, user_id, role_name, token, created_at)
            VALUES (:event_id, :user_id, 'participant', :token, NOW())
        ");

        foreach ($internalUserIds as $uid) {
            /*
            | bin2hex(random_bytes(32)) génère 32 octets aléatoires cryptographiques
            | encodés en hexadécimal → 64 caractères, 256 bits d'entropie.
            | Ce token est stocké en base et envoyé dans le lien du mail.
            */
            $token = bin2hex(random_bytes(32));

            $stmt->execute([
                ':event_id' => $eventId,
                ':user_id'  => $uid,
                ':token'    => $token,
            ]);

            $participantTokens[$uid] = $token;
        }
    }

    if (!empty($guestEmails)) {
        $stmt = $pdo->prepare("
            INSERT INTO event_guests (event_id, guest_email, token, created_at)
            VALUES (:event_id, :guest_email, :token, NOW())
        ");

        foreach ($guestEmails as $guestEmail) {
            $token = bin2hex(random_bytes(32));

            $stmt->execute([
                ':event_id'    => $eventId,
                ':guest_email' => $guestEmail,
                ':token'       => $token,
            ]);

            $guestTokens[$guestEmail] = $token;
        }
    }

    return [
        'participants' => $participantTokens,
        'guests'       => $guestTokens,
    ];
}

/**
 * Libellé lisible pour le statut.
 */
function getStatusLabel(string $status): string
{
    return match ($status) {
        'pending'   => 'En attente',
        'cancelled' => 'Annulé',
        default     => 'Confirmé',
    };
}

/**
 * Libellé lisible pour la priorité.
 */
function getPriorityLabel(string $priority): string
{
    return match ($priority) {
        'low'  => 'Basse',
        'high' => 'Haute',
        default => 'Normale',
    };
}

/**
 * Construit la liste finale des destinataires mails :
 * - utilisateurs internes avec email
 * - invités externes
 * - suppression des doublons par email
 *
 * Si des tokens sont fournis (via $participantTokens / $guestTokens),
 * chaque destinataire reçoit deux URLs personnalisées :
 *   accept_url  → respond_invitation.php?token=XXX&response=accepted
 *   decline_url → respond_invitation.php?token=XXX&response=declined
 *
 * Ces URLs seront insérées dans le template HTML du mail comme boutons
 * Accepter / Refuser. Si aucun token n'est disponible (ex : mail d'annulation),
 * les champs accept_url et decline_url restent vides → boutons absents.
 *
 * @param array<int, string>    $participantTokens  userId → token
 * @param array<string, string> $guestTokens        email  → token
 */
function buildMailRecipients(
    PDO   $pdo,
    array $internalUserIds,
    array $guestEmails,
    array $participantTokens = [],
    array $guestTokens       = []
): array {
    /*
    | URL de base construite à partir de APP_URL défini dans config.php.
    | rtrim() supprime un éventuel slash final pour éviter les doubles slashes.
    | Si APP_URL n'est pas défini (configuration incomplète), les URLs restent vides.
    */
    $baseUrl      = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    $responseBase = $baseUrl !== '' ? $baseUrl . '/backend/Event/respond_invitation.php' : '';

    $recipients = [];

    if (!empty($internalUserIds)) {
        $placeholders = implode(',', array_fill(0, count($internalUserIds), '?'));
        $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id IN ($placeholders)");
        $stmt->execute($internalUserIds);

        foreach ($stmt->fetchAll() as $row) {
            $email = trim((string)($row['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $uid   = (int)$row['id'];
            $token = $participantTokens[$uid] ?? '';

            /*
            | Si le token est présent et que la base URL est connue,
            | on construit les deux liens de réponse personnalisés.
            | Sinon, les champs sont vides → buildInvitationHtml() n'affiche pas les boutons.
            */
            $acceptUrl  = ($token !== '' && $responseBase !== '')
                ? $responseBase . '?token=' . $token . '&response=accepted'
                : '';
            $declineUrl = ($token !== '' && $responseBase !== '')
                ? $responseBase . '?token=' . $token . '&response=declined'
                : '';

            $recipients[] = [
                'email'       => $email,
                'name'        => trim((string)($row['full_name'] ?? '')),
                'accept_url'  => $acceptUrl,
                'decline_url' => $declineUrl,
            ];
        }
    }

    foreach ($guestEmails as $guestEmail) {
        $token = $guestTokens[$guestEmail] ?? '';

        $acceptUrl  = ($token !== '' && $responseBase !== '')
            ? $responseBase . '?token=' . $token . '&response=accepted'
            : '';
        $declineUrl = ($token !== '' && $responseBase !== '')
            ? $responseBase . '?token=' . $token . '&response=declined'
            : '';

        $recipients[] = [
            'email'       => $guestEmail,
            'name'        => '',
            'accept_url'  => $acceptUrl,
            'decline_url' => $declineUrl,
        ];
    }

    $unique = [];
    foreach ($recipients as $recipient) {
        $key = strtolower(trim((string)$recipient['email']));
        if ($key !== '') {
            $unique[$key] = $recipient;
        }
    }

    return array_values($unique);
}

/**
 * Détermine si une modification d'événement est majeure ou mineure.
 *
 * Règle métier simple :
 *   - Au moins un champ MAJEUR a changé  → retourne 'major' → on notifie
 *   - Seuls des champs MINEURS ont changé → retourne 'minor' → pas de notification
 *
 * Champs MAJEURS (logistique, présence physique des participants) :
 *   start, end, all_day, location
 *
 * Champs MINEURS (cosmétiques ou informatifs, pas urgents à signaler) :
 *   title, description, color, priority, reminder_minutes, status
 *
 * @param array   $oldEvent    Ligne complète récupérée depuis la BD AVANT modification
 * @param ?string $newStart    Nouvelle valeur de start (déjà normalisée au format SQL)
 * @param ?string $newEnd      Nouvelle valeur de end   (déjà normalisée au format SQL)
 * @param int     $newAllDay   Nouvelle valeur de all_day (0 ou 1)
 * @param string  $newLocation Nouvelle valeur de location
 *
 * @return string 'major' si au moins un champ majeur a changé, 'minor' sinon
 */
function detectChangeType(
    array   $oldEvent,
    ?string $newStart,
    ?string $newEnd,
    int     $newAllDay,
    string  $newLocation
): string {
    // Table de correspondance : clé en base de données → nouvelle valeur soumise
    // On cast tout en string pour pouvoir comparer uniformément (ex: all_day vient en int de la BD)
    $majorFields = [
        'start'    => (string)($newStart   ?? ''),
        'end'      => (string)($newEnd     ?? ''),
        'all_day'  => (string) $newAllDay,
        'location' => $newLocation,
    ];

    foreach ($majorFields as $field => $newValue) {
        // Ancienne valeur telle qu'elle est stockée en base
        $oldValue = (string)($oldEvent[$field] ?? '');

        // trim() évite les faux positifs liés à des espaces parasites
        if (trim($oldValue) !== trim($newValue)) {
            // Ce champ majeur a changé : la notification est justifiée
            return 'major';
        }
    }

    // Aucun champ majeur n'a bougé : modification mineure, on n'envoie pas de mail
    return 'minor';
}

/**
 * Vérification session utilisateur.
 */
if (!isset($_SESSION['user_id'])) {
    jsonError(401, 'Non connecté');
}

$userId = (int)$_SESSION['user_id'];

/**
 * On accepte uniquement POST.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonError(405, 'Méthode non autorisée');
}

/**
 * Lecture du JSON envoyé par le front.
 */
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    jsonError(400, 'JSON invalide');
}

$action = (string)($data['action'] ?? '');
if (!in_array($action, ['create', 'update', 'delete'], true)) {
    jsonError(400, 'Action invalide');
}

$calendarId = (int)($data['calendar_id'] ?? 0);
if ($calendarId <= 0) {
    jsonError(400, 'calendar_id obligatoire');
}

$title            = trim((string)($data['title'] ?? ''));
$start            = $data['start'] ?? null;
$end              = $data['end'] ?? null;
$allDay           = isset($data['all_day']) ? (int)!!$data['all_day'] : 0;
$description      = trim((string)($data['description'] ?? ''));
$location         = trim((string)($data['location'] ?? ''));
$color            = trim((string)($data['color'] ?? '#2d9cdb'));
$status           = trim((string)($data['status'] ?? 'confirmed'));
$priority         = trim((string)($data['priority'] ?? 'normal'));
$reminderMinutes  = array_key_exists('reminder_minutes', $data) && $data['reminder_minutes'] !== null && $data['reminder_minutes'] !== ''
    ? (int)$data['reminder_minutes']
    : null;
$eventId          = (int)($data['event_id'] ?? 0);
$clientUpdatedAt  = isset($data['client_updated_at']) ? (string)$data['client_updated_at'] : null;
$internalUserIds  = normalizeInternalUserIds($data['internal_user_ids'] ?? []);
$guestEmails      = normalizeGuestEmails($data['guest_emails'] ?? []);

/**
 * Validations simples.
 */
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
    jsonError(400, 'Couleur invalide');
}

if (!in_array($status, ['confirmed', 'pending', 'cancelled'], true)) {
    jsonError(400, 'Statut invalide');
}

if (!in_array($priority, ['low', 'normal', 'high'], true)) {
    jsonError(400, 'Priorité invalide');
}

if ($reminderMinutes !== null && !in_array($reminderMinutes, [5, 15, 30, 60, 1440], true)) {
    jsonError(400, 'Rappel invalide');
}

$startDb = null;
$endDb   = null;

/**
 * Préparation des dates sauf pour delete.
 */
if ($action !== 'delete') {
    if ($title === '') {
        jsonError(400, 'title obligatoire');
    }

    if ($allDay === 1) {
        $startDate = normalizeAllDayDate(is_string($start) ? $start : null);
        $endDate   = normalizeAllDayDate(is_string($end) ? $end : null);

        if ($startDate === null) {
            jsonError(400, 'Date de début invalide');
        }

        if ($endDate === null) {
            $endDate = $startDate;
        }

        if ($endDate < $startDate) {
            jsonError(400, 'La date de fin doit être après la date de début');
        }

        $startDb = $startDate . ' 00:00:00';
        $endDb   = $endDate . ' 23:59:59';
    } else {
        $startDb = normalizeUtcDateTimeString(is_string($start) ? $start : null);
        $endDb   = normalizeUtcDateTimeString(is_string($end) ? $end : null);

        if ($startDb === null) {
            jsonError(400, 'Date/heure de début invalide');
        }

        if ($endDb !== null && $endDb < $startDb) {
            jsonError(400, 'La date de fin doit être après la date de début');
        }
    }
}

try {
    if (!canEditCalendar($pdo, $calendarId, $userId)) {
        jsonError(403, "Accès refusé (pas le droit d'éditer ce calendrier)");
    }

    ensureUsersExist($pdo, $internalUserIds);

    /**
     * CREATE
     */
    if ($action === 'create') {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO events (
                calendar_id,
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
                created_at,
                updated_at
            ) VALUES (
                :calendar_id,
                :user_id,
                :title,
                :description,
                :location,
                :start,
                :end,
                :all_day,
                :color,
                :status,
                :priority,
                :reminder_minutes,
                NOW(),
                NOW()
            )
        ");

        $stmt->execute([
            ':calendar_id'      => $calendarId,
            ':user_id'          => $userId,
            ':title'            => $title,
            ':description'      => ($description === '' ? null : $description),
            ':location'         => ($location === '' ? null : $location),
            ':start'            => $startDb,
            ':end'              => $endDb,
            ':all_day'          => $allDay,
            ':color'            => $color,
            ':status'           => $status,
            ':priority'         => $priority,
            ':reminder_minutes' => $reminderMinutes,
        ]);

        $newId = (int)$pdo->lastInsertId();

        /*
        | saveParticipantsAndGuests() retourne les tokens générés :
        |   ['participants' => [userId => token], 'guests' => [email => token]]
        | On les transmet à buildMailRecipients() pour construire les liens de réponse.
        */
        $tokenData = saveParticipantsAndGuests($pdo, $newId, $internalUserIds, $guestEmails);

        $stmt = $pdo->prepare('SELECT updated_at FROM events WHERE id = :id');
        $stmt->execute([':id' => $newId]);
        $row = $stmt->fetch();

        $pdo->commit();

        $mailResults = [];
        try {
            $recipients = buildMailRecipients(
                $pdo,
                $internalUserIds,
                $guestEmails,
                $tokenData['participants'],
                $tokenData['guests']
            );

            if (!empty($recipients)) {
                $mailResults = sendEventInvitations([
                    'title'          => $title,
                    'description'    => $description,
                    'location'       => $location,
                    'status_label'   => getStatusLabel($status),
                    'priority_label' => getPriorityLabel($priority),
                    'date_text'      => formatEventDateForMail($startDb, $endDb, $allDay === 1),
                ], $recipients);
            }
        } catch (Throwable $mailError) {
            $mailResults[] = [
                'success' => false,
                'email'   => '',
                'error'   => $mailError->getMessage()
            ];
        }

        echo json_encode([
            'success'      => true,
            'event_id'     => $newId,
            'updated_at'   => $row['updated_at'] ?? null,
            'mail_results' => $mailResults
        ]);
        exit;
    }

    /**
     * UPDATE
     */
    if ($action === 'update') {
        if ($eventId <= 0) {
            jsonError(400, 'event_id obligatoire');
        }

        $stmt = $pdo->prepare('SELECT * FROM events WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $eventId]);
        $ev = $stmt->fetch();

        if (!$ev) {
            jsonError(404, 'Évènement introuvable');
        }

        if ((int)$ev['calendar_id'] !== $calendarId) {
            jsonError(400, 'calendar_id ne correspond pas');
        }

        if ((int)$ev['user_id'] !== $userId) {
            jsonError(403, 'Seul le créateur peut modifier cet évènement');
        }

        if ($clientUpdatedAt !== null && $clientUpdatedAt !== (string)$ev['updated_at']) {
            jsonError(409, 'Conflit : évènement modifié ailleurs. Recharge la page.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE events
            SET
                title = :title,
                description = :description,
                location = :location,
                start = :start,
                end = :end,
                all_day = :all_day,
                color = :color,
                status = :status,
                priority = :priority,
                reminder_minutes = :reminder_minutes,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            ':title'            => $title,
            ':description'      => ($description === '' ? null : $description),
            ':location'         => ($location === '' ? null : $location),
            ':start'            => $startDb,
            ':end'              => $endDb,
            ':all_day'          => $allDay,
            ':color'            => $color,
            ':status'           => $status,
            ':priority'         => $priority,
            ':reminder_minutes' => $reminderMinutes,
            ':id'               => $eventId,
        ]);

        /*
        | Même logique que pour CREATE : on capture les nouveaux tokens générés
        | (les anciens ont été supprimés par le DELETE au début de saveParticipantsAndGuests).
        | Cela garantit que chaque mail de mise à jour contient des liens frais.
        */
        $tokenData = saveParticipantsAndGuests($pdo, $eventId, $internalUserIds, $guestEmails);

        $stmt = $pdo->prepare('SELECT updated_at FROM events WHERE id = :id');
        $stmt->execute([':id' => $eventId]);
        $row = $stmt->fetch();

        $pdo->commit();

        // --- Détection du type de modification ---
        // On compare l'ancien état de l'événement ($ev, récupéré plus haut via SELECT *)
        // avec les nouvelles valeurs déjà normalisées au format SQL.
        // Si un champ majeur (start, end, all_day, location) a changé → 'major'.
        // Sinon (titre, couleur, description…) → 'minor'.
        $changeType = detectChangeType($ev, $startDb, $endDb, $allDay, $location);

        $mailResults = [];

        // On n'envoie un e-mail QUE si la modification est majeure.
        // Cela évite de spammer les participants pour un simple changement de couleur ou de titre.
        if ($changeType === 'major') {
            try {
                $recipients = buildMailRecipients(
                    $pdo,
                    $internalUserIds,
                    $guestEmails,
                    $tokenData['participants'],
                    $tokenData['guests']
                );

                if (!empty($recipients)) {
                    $mailResults = sendEventInvitations([
                        'title'          => '[Mise à jour] ' . $title,
                        'description'    => $description,
                        'location'       => $location,
                        'status_label'   => getStatusLabel($status),
                        'priority_label' => getPriorityLabel($priority),
                        'date_text'      => formatEventDateForMail($startDb, $endDb, $allDay === 1),
                    ], $recipients);
                }
            } catch (Throwable $mailError) {
                $mailResults[] = [
                    'success' => false,
                    'email'   => '',
                    'error'   => $mailError->getMessage()
                ];
            }
        }

        echo json_encode([
            'success'      => true,
            'updated_at'   => $row['updated_at'] ?? null,
            'mail_results' => $mailResults
        ]);
        exit;
    }

    /**
     * DELETE
     */
    if ($action === 'delete') {
        if ($eventId <= 0) {
            jsonError(400, 'event_id obligatoire');
        }

        $stmt = $pdo->prepare('SELECT * FROM events WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $eventId]);
        $ev = $stmt->fetch();

        if (!$ev) {
            jsonError(404, 'Évènement introuvable');
        }

        if ((int)$ev['calendar_id'] !== $calendarId) {
            jsonError(400, 'calendar_id ne correspond pas');
        }

        if ((int)$ev['user_id'] !== $userId) {
            jsonError(403, 'Seul le créateur peut supprimer cet évènement');
        }

        // --- Étape 1 : récupérer les destinataires AVANT la suppression ---
        // On doit le faire maintenant car après le DELETE les lignes liées
        // (event_participants, event_guests) pourraient être supprimées en cascade.

        // Récupère les IDs des participants internes de cet événement
        $stmtPart = $pdo->prepare('SELECT user_id FROM event_participants WHERE event_id = :id');
        $stmtPart->execute([':id' => $eventId]);
        $currentParticipantIds = array_map('intval', $stmtPart->fetchAll(PDO::FETCH_COLUMN));

        // Récupère les e-mails des invités externes de cet événement
        $stmtGuests = $pdo->prepare('SELECT guest_email FROM event_guests WHERE event_id = :id');
        $stmtGuests->execute([':id' => $eventId]);
        $currentGuestEmails = $stmtGuests->fetchAll(PDO::FETCH_COLUMN);

        // --- Étape 2 : supprimer l'événement ---
        $stmt = $pdo->prepare('DELETE FROM events WHERE id = :id');
        $stmt->execute([':id' => $eventId]);

        // --- Étape 3 : envoyer le mail d'annulation ---
        // On utilise $ev (chargé plus haut via SELECT *) pour reconstituer les infos de l'événement.
        // L'objet $ev contient encore toutes les données malgré la suppression.
        $mailResults = [];
        try {
            $recipients = buildMailRecipients($pdo, $currentParticipantIds, $currentGuestEmails);

            if (!empty($recipients)) {
                $mailResults = sendEventInvitations([
                    'title'          => '[Annulé] ' . (string)($ev['title'] ?? ''),
                    'description'    => (string)($ev['description'] ?? ''),
                    'location'       => (string)($ev['location'] ?? ''),
                    'status_label'   => 'Annulé',
                    'priority_label' => getPriorityLabel((string)($ev['priority'] ?? 'normal')),
                    'date_text'      => formatEventDateForMail(
                        (string)($ev['start'] ?? ''),
                        (string)($ev['end']   ?? ''),
                        (bool)($ev['all_day'] ?? false)
                    ),
                ], $recipients);
            }
        } catch (Throwable $mailError) {
            $mailResults[] = [
                'success' => false,
                'email'   => '',
                'error'   => $mailError->getMessage()
            ];
        }

        echo json_encode(['success' => true, 'mail_results' => $mailResults]);
        exit;
    }

    jsonError(400, 'Action inconnue');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    jsonError(500, 'Erreur serveur save_event : ' . $e->getMessage());
}