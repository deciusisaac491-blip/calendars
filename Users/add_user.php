<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Protection de session
|--------------------------------------------------------------------------
| Cette API est réservée aux utilisateurs connectés.
| Comme il s'agit d'une API (réponse JSON), on ne redirige pas :
| on retourne un code HTTP 401 avec un message d'erreur JSON.
| C'est cohérent avec les autres API du projet (save_event.php, etc.)
|--------------------------------------------------------------------------
*/
session_start();

/*
|--------------------------------------------------------------------------
| Vérification 1 : utilisateur connecté ?
|--------------------------------------------------------------------------
| Si aucune session active → HTTP 401 "Non autorisé"
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non connecté']);
    exit;
}

/*
|--------------------------------------------------------------------------
| Vérification 2 : l'utilisateur connecté est-il admin ?
|--------------------------------------------------------------------------
| Même si la page users.php est déjà protégée côté HTML, on re-vérifie
| ici côté API. C'est une bonne pratique de sécurité :
| un utilisateur malveillant pourrait appeler cette URL directement,
| sans passer par le formulaire.
|
| Ce principe s'appelle la "défense en profondeur" :
| chaque couche vérifie indépendamment les droits d'accès.
|--------------------------------------------------------------------------
*/
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès refusé.']);
    exit;
}

/*
|--------------------------------------------------------------------------
| API D'AJOUT D'UTILISATEUR
|--------------------------------------------------------------------------
| Ce fichier reçoit des données JSON envoyées par users.php
| puis ajoute un utilisateur dans la table users.
|
| IMPORTANT :
| On utilise le vrai chemin de ton projet :
| ../db.php
|--------------------------------------------------------------------------
*/

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../db.php";

/**
* Fonction utilitaire pour renvoyer une réponse JSON propre.
*/
function jsonResponse(array $payload, int $statusCode = 200): void
{
http_response_code($statusCode);
echo json_encode($payload);
exit;
}

/*
|--------------------------------------------------------------------------
| Vérification méthode HTTP
|--------------------------------------------------------------------------
*/
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
jsonResponse([
"success" => false,
"error" => "Méthode non autorisée"
], 405);
}

/*
|--------------------------------------------------------------------------
| Lecture du JSON envoyé
|--------------------------------------------------------------------------
*/
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
jsonResponse([
"success" => false,
"error" => "JSON invalide"
], 400);
}

/*
|--------------------------------------------------------------------------
| Récupération et nettoyage des champs
|--------------------------------------------------------------------------
*/
$fullName = trim((string)($data["full_name"] ?? ""));
$email    = trim((string)($data["email"]     ?? ""));
$password = trim((string)($data["password"]  ?? ""));

/*
|--------------------------------------------------------------------------
| Récupération et validation du rôle
|--------------------------------------------------------------------------
| On accepte uniquement "admin" ou "user".
| Si la valeur reçue est absente ou invalide, on applique "user" par défaut.
|
| in_array(..., true) : comparaison stricte (type + valeur).
| Cela évite qu'une valeur inattendue passe le contrôle.
|--------------------------------------------------------------------------
*/
$roleInput = trim((string)($data["role"] ?? ""));
$role = in_array($roleInput, ['admin', 'user'], true) ? $roleInput : 'user';

/*
|--------------------------------------------------------------------------
| Validation basique
|--------------------------------------------------------------------------
*/
if ($fullName === "" || $email === "") {
jsonResponse([
"success" => false,
"error" => "Le nom complet et l’e-mail sont obligatoires."
], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
jsonResponse([
"success" => false,
"error" => "Adresse e-mail invalide."
], 400);
}

/*
|--------------------------------------------------------------------------
| Mot de passe
|--------------------------------------------------------------------------
| Pour l’instant, on le rend optionnel.
| Si vide => NULL
| Si renseigné => hash sécurisé
|--------------------------------------------------------------------------
*/
$passwordHash = null;

if ($password !== "") {
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
}

try {
/*
----------------------------------------------------------------------
| Vérifie si l'email existe déjà
----------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
SELECT id
FROM users
WHERE email = :email
LIMIT 1
");
$stmt->execute([
":email" => $email
]);

if ($stmt->fetch()) {
jsonResponse([
"success" => false,
"error" => "Cet e-mail est déjà utilisé."
], 409);
}

/*
----------------------------------------------------------------------
| Insertion utilisateur
----------------------------------------------------------------------
| On inclut maintenant le champ "role" dans l'INSERT.
| La valeur a déjà été validée et nettoyée plus haut.
----------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
INSERT INTO users (full_name, email, password_hash, role, created_at)
VALUES (:full_name, :email, :password, :role, NOW())
");
$stmt->execute([
":full_name" => $fullName,
":email"     => $email,
":password"  => $passwordHash,
":role"      => $role
]);

jsonResponse([
"success" => true,
"message" => "Utilisateur ajouté avec succès."
]);
} catch (Throwable $e) {
jsonResponse([
"success" => false,
"error" => "Erreur serveur : " . $e->getMessage()
], 500);
}