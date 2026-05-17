<?php
declare(strict_types=1);

/*
|==========================================================================
| backend/Event/respond_invitation.php — Réponse à une invitation
|==========================================================================
| Page PUBLIQUE : aucune session requise.
|
| Un invité (interne ou externe) reçoit un e-mail contenant deux liens :
|   → Accepter : ?token=XXX&response=accepted
|   → Refuser  : ?token=XXX&response=declined
|
| Cette page est l'URL cible de ces deux liens.
| Elle lit le token, retrouve l'invitation en base, met à jour la réponse,
| puis affiche une confirmation visuelle simple.
|
| Sécurité :
|   Le token est une chaîne hexadécimale de 64 caractères générée avec
|   bin2hex(random_bytes(32)), ce qui représente 256 bits d'entropie.
|   Il est impossible à deviner. C'est lui qui tient lieu d'authentification.
|==========================================================================
*/

require_once __DIR__ . '/../../db.php';

/*
|--------------------------------------------------------------------------
| Lecture et nettoyage des paramètres GET
|--------------------------------------------------------------------------
*/
$token    = trim((string)($_GET['token']    ?? ''));
$response = trim((string)($_GET['response'] ?? ''));

/*
|--------------------------------------------------------------------------
| Affichage d'une page HTML de résultat
|--------------------------------------------------------------------------
| Cette fonction affiche une page autonome (CSS inline, pas de dépendance)
| et stoppe l'exécution. Elle est appelée quelle que soit l'issue.
|
| Paramètres :
|   $icon    : émoji ou caractère d'icône
|   $color   : couleur hex du titre et de l'icône
|   $heading : titre principal
|   $body    : message explicatif
|--------------------------------------------------------------------------
*/
function renderPage(string $icon, string $color, string $heading, string $body): void
{
    // htmlspecialchars() sur toutes les variables d'affichage pour prévenir le XSS
    $safeIcon    = htmlspecialchars($icon,    ENT_QUOTES, 'UTF-8');
    $safeColor   = htmlspecialchars($color,   ENT_QUOTES, 'UTF-8');
    $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $safeBody    = htmlspecialchars($body,    ENT_QUOTES, 'UTF-8');

    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Réponse à l'invitation — CALENDARS</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0f172a;
            font-family: Arial, sans-serif;
            padding: 24px;
        }

        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 20px;
            padding: 40px 36px;
            max-width: 480px;
            width: 100%;
            text-align: center;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.4);
        }

        .icon    { font-size: 52px; margin-bottom: 18px; }
        .heading { font-size: 22px; font-weight: 800; margin-bottom: 14px; }
        .body    { font-size: 15px; color: #94a3b8; line-height: 1.7; }
        .brand   { font-size: 11px; letter-spacing: 2px; text-transform: uppercase;
                   color: #475569; margin-top: 32px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon"    style="color:{$safeColor}">{$safeIcon}</div>
        <div class="heading" style="color:{$safeColor}">{$safeHeading}</div>
        <div class="body">{$safeBody}</div>
        <div class="brand">CALENDARS</div>
    </div>
</body>
</html>
HTML;
    exit;
}

/*
|--------------------------------------------------------------------------
| Validation des paramètres
|--------------------------------------------------------------------------
| Le token doit être non vide.
| La réponse doit être exactement "accepted" ou "declined".
|
| On utilise in_array(..., true) pour une comparaison stricte (type + valeur).
|--------------------------------------------------------------------------
*/
if ($token === '') {
    renderPage('⚠', '#f59e0b', 'Lien invalide', 'Ce lien ne contient pas de jeton d\'invitation valide.');
}

if (!in_array($response, ['accepted', 'declined'], true)) {
    renderPage('⚠', '#f59e0b', 'Réponse invalide', 'La réponse demandée n\'est pas reconnue. Utilisez les liens fournis dans l\'e-mail.');
}

/*
|--------------------------------------------------------------------------
| Libellés et couleurs selon la réponse
|--------------------------------------------------------------------------
*/
$responseConfig = [
    'accepted' => ['icon' => '✓', 'color' => '#1f9d63', 'heading' => 'Participation confirmée'],
    'declined' => ['icon' => '✗', 'color' => '#d94b4b', 'heading' => 'Invitation déclinée'],
];

$cfg = $responseConfig[$response];

/*
|--------------------------------------------------------------------------
| Recherche du token dans la base de données
|--------------------------------------------------------------------------
| On cherche d'abord dans event_participants (invités internes),
| puis dans event_guests (invités externes) si introuvable.
|
| On fait un JOIN sur la table events pour récupérer le titre de l'événement
| et afficher un message personnalisé sur la page de confirmation.
|
| $foundTable indique dans quelle table le token a été trouvé,
| pour savoir quelle table mettre à jour ensuite.
|--------------------------------------------------------------------------
*/
try {
    $foundRecord = null;
    $foundTable  = '';

    /*
    | Recherche 1 : participants internes
    */
    $stmt = $pdo->prepare('
        SELECT ep.id, ep.response AS current_response, e.title AS event_title
        FROM event_participants ep
        INNER JOIN events e ON e.id = ep.event_id
        WHERE ep.token = :token
        LIMIT 1
    ');
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch();

    if ($row) {
        $foundRecord = $row;
        $foundTable  = 'event_participants';
    }

    /*
    | Recherche 2 : invités externes (si non trouvé dans participants)
    */
    if (!$foundRecord) {
        $stmt = $pdo->prepare('
            SELECT eg.id, eg.response AS current_response, e.title AS event_title
            FROM event_guests eg
            INNER JOIN events e ON e.id = eg.event_id
            WHERE eg.token = :token
            LIMIT 1
        ');
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch();

        if ($row) {
            $foundRecord = $row;
            $foundTable  = 'event_guests';
        }
    }

    /*
    | Token introuvable dans les deux tables → lien invalide ou expiré
    | (peut arriver si l'événement a été modifié après l'envoi du mail,
    |  ce qui regénère de nouveaux tokens)
    */
    if (!$foundRecord) {
        renderPage(
            '⌛', '#64748b',
            'Lien expiré ou invalide',
            'Ce lien n\'est plus valide. Il a peut-être été utilisé, ou l\'événement a été modifié depuis l\'envoi de l\'e-mail.'
        );
    }

    /*
    |----------------------------------------------------------------------
    | Mise à jour de la réponse
    |----------------------------------------------------------------------
    | On met à jour la colonne "response" dans la table correspondante.
    | Si la réponse était déjà enregistrée (double-clic), on l'écrase
    | silencieusement — pas d'erreur, même résultat.
    |
    | Requête préparée obligatoire pour éviter toute injection SQL.
    |----------------------------------------------------------------------
    */
    if ($foundTable === 'event_participants') {
        $stmt = $pdo->prepare('UPDATE event_participants SET response = :response WHERE id = :id');
    } else {
        $stmt = $pdo->prepare('UPDATE event_guests SET response = :response WHERE id = :id');
    }

    $stmt->execute([
        ':response' => $response,
        ':id'       => (int)$foundRecord['id'],
    ]);

    /*
    |----------------------------------------------------------------------
    | Page de confirmation
    |----------------------------------------------------------------------
    | On affiche le titre de l'événement pour confirmer à l'invité
    | qu'il a bien répondu au bon événement.
    |----------------------------------------------------------------------
    */
    $eventTitle = (string)($foundRecord['event_title'] ?? 'cet événement');
    $bodyText   = $response === 'accepted'
        ? "Votre participation à « {$eventTitle} » a bien été enregistrée."
        : "Votre refus pour « {$eventTitle} » a bien été enregistré.";

    renderPage($cfg['icon'], $cfg['color'], $cfg['heading'], $bodyText);

} catch (Throwable $e) {
    // Erreur serveur inattendue : on n'expose pas les détails
    renderPage('⚠', '#f59e0b', 'Erreur serveur', 'Une erreur est survenue. Veuillez réessayer plus tard.');
}
