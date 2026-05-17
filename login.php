<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| login.php — Page de connexion principale de l'application CALENDARS
|--------------------------------------------------------------------------
| Fonctionnement en 4 étapes :
|
|   1. Si l'utilisateur est déjà connecté (session active) → index.php
|   2. Si le formulaire est soumis (POST) → on vérifie email + mot de passe
|   3. Identifiants corrects → on ouvre la session → index.php
|   4. Identifiants incorrects → on affiche un message d'erreur
|
| Sécurité appliquée :
|   - Requêtes préparées PDO : pas d'injection SQL possible
|   - password_verify() : compare le mot de passe saisi au hash stocké en base
|   - session_regenerate_id() : évite les attaques de fixation de session
|   - Message d'erreur générique : ne révèle pas si l'e-mail existe ou non
|   - htmlspecialchars() sur tout ce qui est réaffiché dans le HTML (anti-XSS)
|--------------------------------------------------------------------------
*/

session_start();

// Étape 1 : si l'utilisateur est déjà connecté, on le redirige sans afficher le formulaire
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Connexion à la base de données via PDO (définie dans db.php)
require_once __DIR__ . '/db.php';

// Variable qui contiendra le message d'erreur à afficher dans le HTML
// null = aucune tentative, ou formulaire non soumis encore
$error = null;

/*
|--------------------------------------------------------------------------
| Traitement du formulaire (uniquement en POST)
|--------------------------------------------------------------------------
| On ne traite les données que si l'utilisateur a soumis le formulaire.
| Une simple visite de la page (GET) n'exécute pas ce bloc.
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Récupération des champs et suppression des espaces superflus
    $email    = trim((string)($_POST['email']    ?? ''));
    $password = trim((string)($_POST['password'] ?? ''));

    if ($email === '' || $password === '') {
        // Cas : l'un des deux champs est vide → on affiche un message simple
        $error = 'Veuillez remplir tous les champs.';

    } else {
        /*
        |--------------------------------------------------------------
        | Étape 2 : recherche de l'utilisateur par son e-mail
        |--------------------------------------------------------------
        | On utilise une requête préparée avec un paramètre nommé (:email).
        | Cela empêche toute injection SQL, quelle que soit la valeur saisie.
        |
        | On sélectionne aussi "role" pour pouvoir le stocker en session
        | et contrôler les accès aux pages d'administration.
        |--------------------------------------------------------------
        */
        $stmt = $pdo->prepare('
            SELECT id, password_hash, role
            FROM users
            WHERE email = :email
            LIMIT 1
        ');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        /*
        |--------------------------------------------------------------
        | Vérification du mot de passe avec password_verify()
        |--------------------------------------------------------------
        | password_verify($motDePasseSaisi, $hashStockéEnBase)
        | retourne true uniquement si les deux correspondent.
        |
        | On vérifie les deux conditions ensemble pour ne pas révéler
        | si c'est l'e-mail ou le mot de passe qui est incorrect
        | (message d'erreur volontairement générique).
        |--------------------------------------------------------------
        */
        if (!$user || !password_verify($password, (string)($user['password_hash'] ?? ''))) {
            $error = 'Identifiants incorrects. Vérifiez votre e-mail et votre mot de passe.';

        } else {
            /*
            |----------------------------------------------------------
            | Étape 3 : identifiants corrects → ouverture de session
            |----------------------------------------------------------
            | session_regenerate_id(true) :
            | Génère un nouvel identifiant de session et supprime l'ancien.
            | Cela empêche les attaques de fixation de session
            | (un attaquant ne peut plus forcer un ID de session connu).
            |----------------------------------------------------------
            */
            session_regenerate_id(true);

            // On stocke l'identifiant de l'utilisateur dans la session.
            // C'est cette valeur que toutes les pages protégées vérifieront.
            $_SESSION['user_id'] = (int)$user['id'];

            /*
            |----------------------------------------------------------
            | Stockage du rôle en session avec valeur par défaut sécurisée
            |----------------------------------------------------------
            | On accepte uniquement les valeurs connues : 'admin' ou 'user'.
            | Si la colonne role est absente, NULL, ou contient une valeur
            | inattendue, on stocke 'user' par défaut.
            |
            | Pourquoi cette sécurité ?
            | → Évite qu'un rôle corrompu ou manquant en base donne
            |   accidentellement un accès admin à un utilisateur normal.
            | → Principe du "moindre privilège" : on accorde toujours
            |   le minimum par défaut, jamais le maximum.
            |----------------------------------------------------------
            */
            $roleFromDb = (string)($user['role'] ?? '');
            $_SESSION['role'] = in_array($roleFromDb, ['admin', 'user'], true)
                ? $roleFromDb
                : 'user';

            // Redirection vers le calendrier principal
            header('Location: index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Connexion — CALENDARS</title>

    <!--
        On réutilise la feuille de style principale du projet.
        Elle définit des variables CSS (--bg, --panel, --text, --line, --muted)
        utilisées dans les styles ci-dessous pour rester cohérent visuellement.
    -->
    <link rel="stylesheet" href="css/calendars.css">

    <style>
        /*
        ----------------------------------------------------------------
        | Styles spécifiques à la page de connexion.
        |
        | On s'appuie sur les variables CSS de calendars.css pour ne pas
        | dupliquer les couleurs et rester cohérent avec le reste du projet.
        ----------------------------------------------------------------
        */

        /* Centrage vertical et horizontal de la carte dans la fenêtre */
        .login-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg);
            padding: 24px;
        }

        /* Carte principale qui contient le formulaire */
        .login-card {
            width: 100%;
            max-width: 420px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: 36px 32px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.25);
        }

        /* Sous-titre discret au-dessus du titre ("CALENDARS") */
        .login-brand {
            font-size: 12px;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 6px;
        }

        /* Titre "Connexion" */
        .login-title {
            font-size: 26px;
            font-weight: 800;
            color: var(--text);
            margin: 0 0 28px 0;
        }

        /* Bloc de message d'erreur — affiché uniquement en cas d'échec */
        .login-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f87171;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 14px;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        /* Bouton de connexion étiré sur toute la largeur */
        .btn-login {
            width: 100%;
            padding: 12px;
            font-size: 15px;
            font-weight: 700;
            margin-top: 8px;
        }

        /* Texte d'aide affiché sous le formulaire */
        .login-hint {
            margin-top: 20px;
            font-size: 13px;
            color: var(--muted);
            text-align: center;
            line-height: 1.7;
        }

        .login-hint code {
            font-size: 12px;
            background: rgba(255,255,255,0.07);
            padding: 2px 6px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
<div class="login-page">
    <div class="login-card">

        <!-- En-tête de la carte -->
        <div class="login-brand">CALENDARS</div>
        <h1 class="login-title">Connexion</h1>

        <?php if ($error !== null): ?>
        <!--
            Ce bloc est affiché uniquement si une tentative de connexion a échoué.
            role="alert" indique aux lecteurs d'écran (accessibilité) qu'il s'agit
            d'un message important qui doit être annoncé immédiatement.
            htmlspecialchars() empêche l'injection de code HTML dans le message.
        -->
        <div class="login-error" role="alert">
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php endif; ?>

        <!--
            Formulaire de connexion.

            method="post" : les identifiants transitent dans le corps de la requête HTTP,
                            pas dans l'URL (contrairement à GET). Plus discret et plus sûr.

            action="login.php" : le formulaire est soumis sur la même page,
                                  ce qui permet d'afficher les erreurs facilement.

            novalidate : on désactive la validation HTML5 du navigateur.
                         La validation est gérée côté serveur (PHP), ce qui est
                         plus fiable et plus cohérent.
        -->
        <form method="post" action="login.php" novalidate>

            <div class="form-group">
                <label for="email">Adresse e-mail</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="255"
                    placeholder="votremail@exemple.com"
                    autocomplete="email"
                    required
                    value="<?php
                        /*
                         * On réaffiche l'e-mail saisi pour que l'utilisateur
                         * n'ait pas à le retaper en cas d'erreur.
                         * htmlspecialchars() est obligatoire pour éviter le XSS.
                         */
                        echo htmlspecialchars(
                            (string)($_POST['email'] ?? ''),
                            ENT_QUOTES,
                            'UTF-8'
                        );
                    ?>"
                >
            </div>

            <div class="form-group">
                <label for="password">Mot de passe</label>
                <!--
                    type="password" : masque les caractères saisis.
                    On ne réaffiche jamais le mot de passe dans le HTML (sécurité).
                -->
                <input
                    type="password"
                    id="password"
                    name="password"
                    maxlength="255"
                    placeholder="••••••••"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button type="submit" class="btn btn-primary btn-login">
                Se connecter
            </button>

        </form>

        <p class="login-hint">
            Pas encore de compte ?<br>
            Demande à l'administrateur de créer ton accès via <code>users.php</code>.
        </p>

    </div>
</div>
</body>
</html>
