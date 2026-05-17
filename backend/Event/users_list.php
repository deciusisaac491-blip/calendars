<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| users_list.php
|--------------------------------------------------------------------------
| Ce fichier renvoie la liste des utilisateurs internes.
| Il sera utilisé dans la modale événement pour remplir
| le champ "participants internes".
|--------------------------------------------------------------------------
*/

session_start();
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../../db.php";

/**
* Réponse JSON d'erreur standardisée.
*/
function jsonError(int $code, string $msg): void
{
http_response_code($code);
echo json_encode(["error" => $msg]);
exit;
}

/*
|--------------------------------------------------------------------------
| Vérification de session
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION["user_id"])) {
jsonError(401, "Non connecté");
}

try {
/*
----------------------------------------------------------------------
| On récupère tous les utilisateurs
----------------------------------------------------------------------
| label :
| - full_name + email si dispo
| - sinon email
| - sinon User #ID
----------------------------------------------------------------------
*/
$stmt = $pdo->query("
SELECT id, full_name, email
FROM users
ORDER BY full_name ASC, id ASC
");

$rows = $stmt->fetchAll();
$users = [];

foreach ($rows as $row) {
$id = (int)$row["id"];
$fullName = trim((string)($row["full_name"] ?? ""));
$email = trim((string)($row["email"] ?? ""));

$label = $fullName !== ""
? ($email !== "" ? $fullName . " (" . $email . ")" : $fullName)
: ($email !== "" ? $email : "User #" . $id);

$users[] = [
"id" => $id,
"full_name" => $fullName !== "" ? $fullName : null,
"email" => $email !== "" ? $email : null,
"label" => $label
];
}

echo json_encode([
"users" => $users
]);
} catch (Throwable $e) {
jsonError(500, "Erreur users_list : " . $e->getMessage());
}
