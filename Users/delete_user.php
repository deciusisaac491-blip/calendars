<?php
declare(strict_types=1);

/*
|==========================================================================
| Users/delete_user.php — API de suppression d'un utilisateur
|==========================================================================
| Ce fichier reçoit une requête POST avec un JSON contenant l'id
| de l'utilisateur à supprimer, puis l'efface de la base de données.
|
| Réponses possibles :
|   200  { "success": true }
|   400  { "success": false, "error": "..." }  — id manquant ou invalide
|   401  { "success": false, "error": "..." }  — non connecté
|   403  { "success": false, "error": "..." }  — non admin / auto-suppression
|                                                 / dernier admin
|   404  { "success": false, "error": "..." }  — utilisateur introuvable
|   405  { "success": false, "error": "..." }  — mauvaise méthode HTTP
|   500  { "success": false, "error": "..." }  — erreur serveur inattendue
|==========================================================================
*/

session_start();
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../db.php";

/*
|--------------------------------------------------------------------------
| Vérification 1 : l'utilisateur est-il connecté ?
|--------------------------------------------------------------------------
| Si aucune session active → HTTP 401 "Non autorisé".
| On répond en JSON car c'est une API, pas une page HTML.
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
| Même si users.php est déjà protégée côté HTML, cette API peut être
| appelée directement depuis un outil externe (Postman, curl...).
|
| On re-vérifie donc ici le rôle, indépendamment de la page.
| C'est le principe de "défense en profondeur" :
| chaque couche sécurise elle-même ses accès, sans s'appuyer
| uniquement sur les couches précédentes.
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
| On n'accepte que POST. Une requête GET ou autre est rejetée.
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
| php://input contient le corps brut de la requête HTTP.
| json_decode(..., true) le convertit en tableau PHP associatif.
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
| Validation de l'id reçu
|--------------------------------------------------------------------------
| On s'assure que c'est bien un entier strictement positif.
|
| filter_var($val, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
| retourne false si la valeur est absente, nulle, flottante, ou < 1.
|
| Cela empêche toute tentative d'injection via un id malformé.
|--------------------------------------------------------------------------
*/
$idRaw = $data['id'] ?? null;
$id    = filter_var($idRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($id === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Identifiant invalide.']);
    exit;
}

/*
|--------------------------------------------------------------------------
| Vérification 3 : l'admin ne peut pas supprimer son propre compte
|--------------------------------------------------------------------------
| On compare l'id cible avec l'id de session de l'admin connecté.
| Si ce sont les mêmes → refus immédiat.
|
| Cette vérification est aussi faite côté HTML (bouton absent sur sa
| propre ligne), mais on la répète ici car le HTML est contournable
| (désactivation des attributs via les outils développeur du navigateur).
|--------------------------------------------------------------------------
*/
if ($id === (int)$_SESSION['user_id']) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Vous ne pouvez pas supprimer votre propre compte.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Opérations sur la base de données
|--------------------------------------------------------------------------
| On encapsule tout dans un try/catch pour intercepter les erreurs PDO
| et renvoyer une réponse propre au lieu d'un crash PHP.
|--------------------------------------------------------------------------
*/
try {

    /*
    |----------------------------------------------------------------------
    | Récupération du rôle de l'utilisateur cible
    |----------------------------------------------------------------------
    | Avant de supprimer, on vérifie si l'utilisateur existe, et surtout
    | quel est son rôle, car on doit protéger le dernier admin.
    |----------------------------------------------------------------------
    */
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $target = $stmt->fetch();

    if (!$target) {
        // L'utilisateur demandé n'existe pas en base
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Utilisateur introuvable.']);
        exit;
    }

    /*
    |----------------------------------------------------------------------
    | Vérification 4 : protection du dernier administrateur
    |----------------------------------------------------------------------
    | Si la cible est un admin, on compte combien d'admins existent.
    | Si c'est le seul admin restant, on refuse la suppression.
    |
    | Pourquoi cette règle ?
    | → Sans au moins un admin, personne ne pourrait gérer les comptes.
    | → L'application se retrouverait dans un état irrécupérable.
    |
    | On n'effectue ce comptage QUE si la cible est admin :
    | inutile de compter pour un utilisateur normal.
    |----------------------------------------------------------------------
    */
    if ($target['role'] === 'admin') {
        $stmtCount  = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
        $adminCount = (int)$stmtCount->fetchColumn();

        if ($adminCount <= 1) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error'   => 'Impossible de supprimer le dernier administrateur.'
            ]);
            exit;
        }
    }

    /*
    |----------------------------------------------------------------------
    | Vérification 5 : l'utilisateur possède-t-il des calendriers ?
    |----------------------------------------------------------------------
    | Avant de supprimer, on compte les calendriers dont cet utilisateur
    | est propriétaire (colonne owner_id dans la table calendars).
    |
    | Pourquoi ce contrôle ?
    | → La table "calendars" a une clé étrangère sur users.id.
    |   Si l'utilisateur possède encore des calendriers, MySQL peut
    |   refuser la suppression avec une erreur de contrainte d'intégrité
    |   (SQLSTATE 23000), selon la configuration de la base de données.
    |
    | On préfère détecter ce cas AVANT le DELETE pour trois raisons :
    |   1. Afficher un message métier lisible (pas une erreur SQL brute)
    |   2. Laisser l'admin décider quoi faire de ces calendriers
    |   3. Éviter toute suppression accidentelle de données en cascade
    |
    | HTTP 409 Conflict : code sémantique pour "la requête est valide,
    | mais elle entre en conflit avec l'état actuel des données".
    | C'est plus précis que 403 (accès interdit) ou 400 (requête invalide).
    |----------------------------------------------------------------------
    */
    $stmtCal  = $pdo->prepare('SELECT COUNT(*) FROM calendars WHERE owner_id = :id');
    $stmtCal->execute([':id' => $id]);
    $calCount = (int)$stmtCal->fetchColumn();

    if ($calCount > 0) {
        // Accord grammatical : "1 calendrier" vs "3 calendriers"
        $label = $calCount === 1 ? 'calendrier' : 'calendriers';

        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error'   => "Cet utilisateur possède {$calCount} {$label}. "
                       . "Supprimez ou transférez ses calendriers avant de pouvoir supprimer ce compte."
        ]);
        exit;
    }

    /*
    |----------------------------------------------------------------------
    | Vérification 6 : l'utilisateur est-il créateur d'événements ?
    |----------------------------------------------------------------------
    | La table "events" contient une colonne user_id qui référence
    | users.id. Elle désigne le créateur de chaque événement.
    |
    | Pourquoi ce contrôle ?
    | → MySQL peut refuser la suppression si une contrainte de clé
    |   étrangère est active entre events.user_id et users.id.
    |   Sans cette vérification, l'erreur SQL brute remonterait
    |   jusqu'au catch et s'afficherait dans le toast.
    |
    | Quelle différence avec les calendriers (vérification 5) ?
    | → Les calendriers ont un "owner" (propriétaire métier) que l'on
    |   peut transférer vers un autre admin avant suppression.
    | → Les événements ont un "créateur" qui est une donnée d'historique.
    |   Pour l'instant, on refuse la suppression tant que des événements
    |   existent. Une future fonctionnalité pourrait permettre de les
    |   réassigner ou de les supprimer en lot avant ce blocage.
    |
    | HTTP 409 Conflict : même code que pour les calendriers.
    | Sémantique : "la requête est valide mais entre en conflit avec
    | l'état actuel des données" — plus précis que 400 ou 403.
    |----------------------------------------------------------------------
    */
    $stmtEvt  = $pdo->prepare('SELECT COUNT(*) FROM events WHERE user_id = :id');
    $stmtEvt->execute([':id' => $id]);
    $evtCount = (int)$stmtEvt->fetchColumn();

    if ($evtCount > 0) {
        // Accord grammatical : "1 événement" vs "3 événements"
        $label = $evtCount === 1 ? 'événement' : 'événements';

        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error'   => "Cet utilisateur a créé {$evtCount} {$label}. "
                       . "Supprimez ses événements avant de pouvoir supprimer ce compte."
        ]);
        exit;
    }

    /*
    |----------------------------------------------------------------------
    | Suppression effective
    |----------------------------------------------------------------------
    | Toutes les vérifications sont passées : on peut supprimer.
    | On utilise une requête préparée pour éviter toute injection SQL.
    |----------------------------------------------------------------------
    */
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute([':id' => $id]);

    // Succès : on renvoie un JSON simple
    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    // Erreur inattendue côté serveur (connexion DB perdue, etc.)
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Erreur serveur : ' . $e->getMessage()
    ]);
}
