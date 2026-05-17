<?php
declare(strict_types=1);

/*
|==========================================================================
| Users/transfer_calendars.php — API de transfert de calendriers
|==========================================================================
| Ce fichier reçoit une requête POST avec un JSON contenant :
|   - "from_id" : l'id de l'utilisateur qui cède ses calendriers
|   - "to_id"   : l'id de l'administrateur qui les récupère
|
| Il transfère tous les calendriers appartenant à "from_id" vers "to_id".
|
| Réponses possibles :
|   200  { "success": true,  "count": X }    — X calendriers transférés
|   400  { "success": false, "error": "..." } — données invalides
|   401  { "success": false, "error": "..." } — non connecté
|   403  { "success": false, "error": "..." } — non admin / destination non admin
|   404  { "success": false, "error": "..." } — utilisateur introuvable
|   405  { "success": false, "error": "..." } — mauvaise méthode HTTP
|   500  { "success": false, "error": "..." } — erreur serveur
|==========================================================================
*/

session_start();
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../db.php";

/*
|--------------------------------------------------------------------------
| Vérification 1 : l'utilisateur est-il connecté ?
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non connecté.']);
    exit;
}

/*
|--------------------------------------------------------------------------
| Vérification 2 : l'utilisateur connecté est-il admin ?
|--------------------------------------------------------------------------
| Défense en profondeur : on vérifie ici même si users.php est protégée.
| Cette API peut être appelée directement via Postman ou curl.
|--------------------------------------------------------------------------
*/
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès refusé.']);
    exit;
}

/*
|--------------------------------------------------------------------------
| Vérification de la méthode HTTP
|--------------------------------------------------------------------------
*/
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

/*
|--------------------------------------------------------------------------
| Lecture et décodage du corps JSON
|--------------------------------------------------------------------------
*/
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON invalide.']);
    exit;
}

/*
|--------------------------------------------------------------------------
| Validation de from_id et to_id
|--------------------------------------------------------------------------
| Les deux doivent être des entiers strictement positifs.
| filter_var retourne false si la valeur est absente, null ou < 1.
|--------------------------------------------------------------------------
*/
$fromId = filter_var($data['from_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$toId   = filter_var($data['to_id']   ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($fromId === false || $toId === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Identifiants invalides.']);
    exit;
}

/*
|--------------------------------------------------------------------------
| Vérification 3 : from_id ≠ to_id
|--------------------------------------------------------------------------
| On ne peut pas transférer des calendriers vers soi-même.
| Même si le <select> l'empêche côté interface, on le vérifie ici
| car la requête peut être forgée manuellement.
|--------------------------------------------------------------------------
*/
if ($fromId === $toId) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'La source et la destination doivent être différentes.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Opérations sur la base de données
|--------------------------------------------------------------------------
*/
try {

    /*
    |----------------------------------------------------------------------
    | Vérification 4 : la destination existe et est bien un admin
    |----------------------------------------------------------------------
    | On vérifie que l'utilisateur "to_id" :
    |   - existe en base de données
    |   - a le rôle 'admin'
    |
    | On ne transfère des calendriers que vers un administrateur pour
    | garantir que les données restent gérées par quelqu'un d'habilité.
    |----------------------------------------------------------------------
    */
    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $toId]);
    $destination = $stmt->fetch();

    if (!$destination) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Utilisateur destination introuvable.']);
        exit;
    }

    if ($destination['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Le destinataire doit être un administrateur.'
        ]);
        exit;
    }

    /*
    |----------------------------------------------------------------------
    | Vérification 5 : la source possède-t-elle des calendriers ?
    |----------------------------------------------------------------------
    | On compte d'abord le nombre de calendriers à transférer.
    | S'il n'y en a aucun, l'opération est inutile : on refuse
    | avec un message clair plutôt que d'exécuter un UPDATE silencieux.
    |
    | On réutilise ce comptage dans la réponse JSON (champ "count")
    | pour afficher un message de succès précis côté interface.
    |----------------------------------------------------------------------
    */
    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM calendars WHERE owner_id = :id');
    $stmtCount->execute([':id' => $fromId]);
    $calCount  = (int)$stmtCount->fetchColumn();

    if ($calCount === 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Cet utilisateur ne possède aucun calendrier à transférer.'
        ]);
        exit;
    }

    /*
    |----------------------------------------------------------------------
    | Transfert effectif
    |----------------------------------------------------------------------
    | Un seul UPDATE suffit : on change le propriétaire (owner_id) de
    | tous les calendriers appartenant à "from_id" vers "to_id".
    |
    | Les événements, partages et participants liés à ces calendriers
    | ne sont pas touchés : seule la propriété change.
    |
    | On utilise une requête préparée pour éviter toute injection SQL.
    |----------------------------------------------------------------------
    */
    $stmtUpdate = $pdo->prepare('UPDATE calendars SET owner_id = :to_id WHERE owner_id = :from_id');
    $stmtUpdate->execute([
        ':to_id'   => $toId,
        ':from_id' => $fromId
    ]);

    /*
    |----------------------------------------------------------------------
    | Réponse de succès
    |----------------------------------------------------------------------
    | On renvoie le nombre de calendriers transférés (calCount).
    | Le JS l'utilisera pour construire un message de succès précis :
    | "2 calendriers transférés" plutôt qu'un simple "succès".
    |----------------------------------------------------------------------
    */
    echo json_encode([
        'success' => true,
        'count'   => $calCount
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Erreur serveur : ' . $e->getMessage()
    ]);
}
