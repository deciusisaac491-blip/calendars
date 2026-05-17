<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Protection de session et vérification du rôle admin
|--------------------------------------------------------------------------
| Cette page est réservée aux administrateurs uniquement.
|
| Deux vérifications sont faites dans l'ordre :
|   1. L'utilisateur est-il connecté ?       → sinon : login.php
|   2. A-t-il le rôle 'admin' en session ?   → sinon : index.php
|
| Pourquoi deux vérifications séparées ?
| → La première protège contre les visiteurs non connectés.
| → La seconde protège contre les utilisateurs connectés mais sans droits.
|
| On redirige un user normal vers index.php (le calendrier) plutôt que
| vers une page d'erreur : comportement simple et non agressif.
|--------------------------------------------------------------------------
*/
session_start();

if (!isset($_SESSION['user_id'])) {
    // Pas de session du tout → retour à la page de connexion
    header('Location: login.php');
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    // Connecté mais pas admin → retour au calendrier, sans message d'erreur
    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| PAGE DE GESTION DES UTILISATEURS
|--------------------------------------------------------------------------
| Cette page permet :
| - d'afficher la liste des utilisateurs existants
| - d'ajouter un nouvel utilisateur via AJAX
|
| IMPORTANT :
| On utilise ici db.php placé à la racine du projet.
|--------------------------------------------------------------------------
*/
require_once __DIR__ . "/db.php";

/*
|--------------------------------------------------------------------------
| Chargement des utilisateurs existants
|--------------------------------------------------------------------------
| On trie les utilisateurs du plus récent au plus ancien.
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| Chargement des utilisateurs avec comptage des calendriers
|--------------------------------------------------------------------------
| On utilise un LEFT JOIN entre "users" et "calendars" pour récupérer,
| en une seule requête, le nombre de calendriers possédés par chaque user.
|
| LEFT JOIN : on garde tous les utilisateurs, même ceux sans calendrier.
|   → Pour un user sans calendrier, COUNT(c.id) retourne 0.
|   → Pour un user avec 3 calendriers, COUNT(c.id) retourne 3.
|
| GROUP BY : obligatoire quand on utilise COUNT() avec d'autres colonnes.
|   On groupe par toutes les colonnes non agrégées pour être compatible
|   avec MySQL en mode ONLY_FULL_GROUP_BY (activé par défaut).
|
| Ce calendar_count servira à afficher ou masquer le bouton "Transférer"
| et à désactiver le bouton "Supprimer" tant qu'il y a des calendriers.
|--------------------------------------------------------------------------
*/
$stmt = $pdo->query("
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.role,
        u.created_at,
        COUNT(c.id) AS calendar_count
    FROM users u
    LEFT JOIN calendars c ON c.owner_id = u.id
    GROUP BY u.id, u.full_name, u.email, u.role, u.created_at
    ORDER BY u.created_at DESC, u.id DESC
");
$users = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Comptage des administrateurs
|--------------------------------------------------------------------------
| On calcule ici, côté serveur, le nombre d'admins dans la liste.
| Cette valeur permet de désactiver visuellement le bouton "Supprimer"
| sur la ligne du dernier admin restant.
|
| On utilise array_filter() pour ne garder que les users dont le rôle
| est 'admin', puis count() pour en avoir le nombre.
|
| Note : la vraie protection est côté API (delete_user.php).
| Ce calcul ne sert qu'à améliorer le confort visuel de l'interface.
|--------------------------------------------------------------------------
*/
$adminCount = count(array_filter($users, fn(array $u): bool => $u['role'] === 'admin'));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des utilisateurs - CALENDARS</title>
    <link rel="stylesheet" href="css/calendars.css">

    <style>
        /*
        ----------------------------------------------------------------------
        | Styles spécifiques à la page users.php
        ----------------------------------------------------------------------
        | On garde ce CSS ici pour éviter de casser le fichier principal
        | calendars.css. C'est un complément local à cette page.
        ----------------------------------------------------------------------
        */

        .users-page {
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            padding: 24px;
        }

        .users-container {
            max-width: 1100px;
            margin: 0 auto;
        }

        .users-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .users-title {
            margin: 0;
            font-size: 28px;
            font-weight: 800;
        }

        .users-subtitle {
            margin: 6px 0 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .users-grid {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 20px;
        }

        .users-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.18);
        }

        .users-card h2 {
            margin-top: 0;
            margin-bottom: 16px;
            font-size: 20px;
        }

        .users-table-wrap {
            overflow-x: auto;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
            color: #111827;
            border-radius: 12px;
            overflow: hidden;
        }

        .users-table th,
        .users-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            font-size: 14px;
        }

        .users-table th {
            background: #f3f4f6;
            font-weight: 700;
        }

        .users-empty {
            color: var(--muted);
            font-size: 14px;
            margin: 0;
        }

        .page-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        @media (max-width: 900px) {
            .users-grid {
                grid-template-columns: 1fr;
            }
        }

        /*
        ----------------------------------------------------------------------
        | Cellule Actions : plusieurs boutons côte à côte
        ----------------------------------------------------------------------
        | Quand un utilisateur a des calendriers, deux boutons cohabitent
        | dans la même cellule. On les aligne avec flexbox.
        ----------------------------------------------------------------------
        */
        .actions-cell {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }

        /*
        ----------------------------------------------------------------------
        | Bouton de transfert (variante bleue)
        ----------------------------------------------------------------------
        */
        .btn-transfer {
            background: transparent;
            color: #2d9cdb;
            border: 1px solid #2d9cdb;
            border-radius: 8px;
            padding: 5px 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
            white-space: nowrap;
        }

        .btn-transfer:hover:not(:disabled) {
            background: #2d9cdb;
            color: #fff;
        }

        .btn-transfer:disabled {
            color: var(--muted);
            border-color: var(--line);
            cursor: not-allowed;
            opacity: 0.6;
        }

        /*
        ----------------------------------------------------------------------
        | Panneau de transfert (même mécanique que le bandeau de suppression)
        ----------------------------------------------------------------------
        | Caché sous l'écran par défaut (transform: translateY(100%)).
        | La classe .visible le fait glisser vers le haut.
        | Bordure bleue pour le distinguer du bandeau rouge de suppression.
        ----------------------------------------------------------------------
        */
        .transfer-panel {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--panel);
            border-top: 2px solid #2d9cdb;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            z-index: 1001;
            transform: translateY(100%);
            transition: transform 0.25s ease;
            box-shadow: 0 -6px 24px rgba(0, 0, 0, 0.35);
        }

        .transfer-panel.visible {
            transform: translateY(0);
        }

        .transfer-panel-text {
            font-size: 14px;
            color: var(--text);
            margin: 0;
            white-space: nowrap;
        }

        /* Le nom de l'utilisateur source est mis en bleu */
        .transfer-panel-text strong {
            color: #2d9cdb;
        }

        /* Le <select> des administrateurs disponibles */
        .transfer-select {
            flex: 1;
            min-width: 180px;
            padding: 7px 10px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--bg);
            color: var(--text);
            font-size: 14px;
        }

        /* Bouton de confirmation du transfert (bleu plein) */
        .btn-transfer-confirm {
            background: #2d9cdb;
            color: #fff;
            border: 1px solid #2d9cdb;
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s;
            white-space: nowrap;
        }

        .btn-transfer-confirm:hover:not(:disabled) {
            background: #1a7fc1;
        }

        .btn-transfer-confirm:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /*
        ----------------------------------------------------------------------
        | Bouton de suppression (variante danger)
        ----------------------------------------------------------------------
        | Style discret par défaut (contour rouge, fond transparent).
        | Au survol, il se remplit en rouge : l'intention destructive
        | est visible, mais pas agressive au premier regard.
        ----------------------------------------------------------------------
        */
        .btn-delete {
            background: transparent;
            color: #ef4444;
            border: 1px solid #ef4444;
            border-radius: 8px;
            padding: 5px 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
            white-space: nowrap;
        }

        .btn-delete:hover:not(:disabled) {
            background: #ef4444;
            color: #fff;
        }

        /*
        | État désactivé — utilisé pour le dernier administrateur.
        | Le curseur "not-allowed" indique visuellement que l'action
        | est bloquée sans qu'on ait besoin de cliquer pour le savoir.
        */
        .btn-delete:disabled {
            color: var(--muted);
            border-color: var(--line);
            cursor: not-allowed;
            opacity: 0.6;
        }

        /*
        ----------------------------------------------------------------------
        | Bandeau de confirmation de suppression
        ----------------------------------------------------------------------
        | Affiché en bas de l'écran (position: fixed).
        | Par défaut, il est caché hors de l'écran (translateY 100%).
        | Quand la classe .visible est ajoutée par JS, il glisse vers le haut.
        |
        | Cette technique (transform + transition) est préférable à
        | display:none/block car elle permet une animation CSS fluide.
        ----------------------------------------------------------------------
        */
        .delete-confirm-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--panel);
            border-top: 2px solid #ef4444;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            z-index: 1000;
            transform: translateY(100%);
            transition: transform 0.25s ease;
            box-shadow: 0 -6px 24px rgba(0, 0, 0, 0.35);
        }

        /* Classe ajoutée par JS pour faire apparaître le bandeau */
        .delete-confirm-bar.visible {
            transform: translateY(0);
        }

        /* Texte central du bandeau : "Supprimer [Nom] ?" */
        .delete-confirm-text {
            flex: 1;
            font-size: 14px;
            color: var(--text);
            margin: 0;
        }

        /* Le nom de l'utilisateur cible est mis en rouge pour attirer l'attention */
        .delete-confirm-text strong {
            color: #ef4444;
        }

        /* Bouton de confirmation final (rouge plein) */
        .btn-delete-confirm {
            background: #ef4444;
            color: #fff;
            border: 1px solid #ef4444;
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-delete-confirm:hover:not(:disabled) {
            background: #dc2626;
        }

        .btn-delete-confirm:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <div class="users-page">
        <div class="users-container">

            <div class="users-header">
                <div>
                    <h1 class="users-title">Gestion des utilisateurs</h1>
                    <p class="users-subtitle">
                        Cette page permet d’ajouter des utilisateurs internes pour les utiliser
                        ensuite comme participants dans les événements.
                    </p>
                </div>

                <div class="page-actions">
                    <a href="index.php" class="btn">Retour au calendrier</a>
                </div>
            </div>

            <div class="users-grid">

                <!-- ======================================================
                     CARTE : FORMULAIRE D’AJOUT
                ======================================================= -->
                <section class="users-card">
                    <h2>Ajouter un utilisateur</h2>

                    <form id="form-add-user">
                        <div class="form-group">
                            <label for="full_name">Nom complet</label>
                            <input
                                type="text"
                                id="full_name"
                                name="full_name"
                                maxlength="150"
                                placeholder="Ex. Isaac Decius"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="email">Adresse e-mail</label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                maxlength="255"
                                placeholder="Ex. isaac@email.com"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="password">Mot de passe (optionnel)</label>
                            <input
                                type="text"
                                id="password"
                                name="password"
                                maxlength="255"
                                placeholder="Tu peux le laisser vide pour l’instant"
                            >
                        </div>

                        <!--
                            Champ de sélection du rôle.
                            "user" est sélectionné par défaut (principe du moindre privilège).
                            La valeur sera transmise en JSON à Users/add_user.php.
                        -->
                        <div class="form-group">
                            <label for="role">Rôle</label>
                            <select id="role" name="role">
                                <option value="user" selected>Utilisateur</option>
                                <option value="admin">Administrateur</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            Ajouter l’utilisateur
                        </button>
                    </form>
                </section>

                <!-- ======================================================
                     CARTE : LISTE DES UTILISATEURS
                ======================================================= -->
                <section class="users-card">
                    <h2>Liste des utilisateurs</h2>

                    <?php if (count($users) === 0): ?>
                        <p class="users-empty">Aucun utilisateur pour le moment.</p>
                    <?php else: ?>
                        <div class="users-table-wrap">
                            <table class="users-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nom</th>
                                        <th>Email</th>
                                        <th>Rôle</th>
                                        <th>Créé le</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <!--
                                    L'id "users-table-body" est utilisé par le JS
                                    pour la délégation d'événements sur les boutons
                                    de suppression (un seul listener sur le tbody
                                    au lieu d'un par ligne).
                                -->
                                <tbody id="users-table-body">
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?php echo (int)$user["id"]; ?></td>
                                            <td><?php echo htmlspecialchars((string)$user["full_name"], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)$user["email"], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)$user["role"], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)$user["created_at"], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <!--
                                                Cellule Actions — 4 cas possibles selon le profil de l'utilisateur.
                                                La classe "actions-cell" active le flexbox pour aligner les boutons.
                                            -->
                                            <td class="actions-cell">
                                            <?php if ((int)$user["id"] === (int)$_SESSION['user_id']): ?>
                                                <!--
                                                    Cas 1 : c'est le compte de l'admin connecté.
                                                    On n'affiche aucun bouton — pas d'auto-suppression possible.
                                                    La cellule reste vide pour ne pas perturber le tableau.
                                                -->

                                            <?php elseif ($user["role"] === "admin" && $adminCount <= 1): ?>
                                                <!--
                                                    Cas 2 : c'est le dernier administrateur de l'application.
                                                    Un seul bouton désactivé avec explication.
                                                    On n'offre pas le transfert car la suppression sera
                                                    de toute façon bloquée par l'API (dernier admin).
                                                -->
                                                <button
                                                    class="btn-delete"
                                                    disabled
                                                    title="Suppression impossible : dernier administrateur"
                                                >
                                                    Dernier administrateur
                                                </button>

                                            <?php elseif ((int)$user["calendar_count"] > 0): ?>
                                                <!--
                                                    Cas 3 : l'utilisateur possède des calendriers.
                                                    → Bouton "Transférer" actif : permet de transférer
                                                      les calendriers vers un autre admin avant suppression.
                                                    → Bouton "Supprimer" visible mais désactivé tant que
                                                      les calendriers n'ont pas été transférés.
                                                    data-id    : id de l'utilisateur
                                                    data-name  : nom affiché dans le panneau de confirmation
                                                    data-count : nombre de calendriers (affiché dans le panneau)
                                                -->
                                                <button
                                                    class="btn-transfer"
                                                    data-id="<?php echo (int)$user["id"]; ?>"
                                                    data-name="<?php echo htmlspecialchars((string)$user["full_name"], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-count="<?php echo (int)$user["calendar_count"]; ?>"
                                                >
                                                    Transférer
                                                </button>
                                                <button
                                                    class="btn-delete"
                                                    disabled
                                                    title="Suppression impossible : transférez d'abord les calendriers"
                                                >
                                                    Supprimer
                                                </button>

                                            <?php else: ?>
                                                <!--
                                                    Cas 4 : aucun calendrier, pas le dernier admin, pas soi-même.
                                                    La suppression directe est possible.
                                                -->
                                                <button
                                                    class="btn-delete"
                                                    data-id="<?php echo (int)$user["id"]; ?>"
                                                    data-name="<?php echo htmlspecialchars((string)$user["full_name"], ENT_QUOTES, 'UTF-8'); ?>"
                                                >
                                                    Supprimer
                                                </button>

                                            <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </div>

    <!--
    =========================================================================
    | Panneau de transfert de calendriers
    =========================================================================
    | Même mécanique que le bandeau de suppression :
    |   - caché par défaut (translateY 100%)
    |   - la classe .visible le fait remonter via transition CSS
    |
    | Il contient un <select> peuplé dynamiquement par JS avec la liste
    | des administrateurs disponibles (source exclue).
    =========================================================================
    -->
    <div id="transfer-panel" class="transfer-panel"
         role="alertdialog" aria-labelledby="transfer-panel-label">

        <!-- Texte avec le nom de la source et le nombre de calendriers -->
        <p class="transfer-panel-text" id="transfer-panel-label">
            Transférer <strong id="transfer-source-name"></strong>
            (<span id="transfer-cal-count"></span>) vers :
        </p>

        <!--
            Le <select> est vide dans le HTML.
            Le JS le remplit dynamiquement à chaque ouverture du panneau,
            en filtrant la liste ALL_ADMINS pour exclure l'utilisateur source.
        -->
        <select id="transfer-select" class="transfer-select" aria-label="Choisir le destinataire">
        </select>

        <!-- Bouton Annuler : cache le panneau sans rien faire -->
        <button id="transfer-cancel-btn" type="button" class="btn">
            Annuler
        </button>

        <!-- Bouton Confirmer : déclenche la requête de transfert -->
        <button id="transfer-confirm-btn" type="button" class="btn-transfer-confirm">
            Confirmer le transfert
        </button>
    </div>

    <!--
    =========================================================================
    | Bandeau de confirmation de suppression
    =========================================================================
    | Ce bandeau est caché par défaut (hors de l'écran via CSS transform).
    | Il apparaît en bas de l'écran quand l'admin clique sur "Supprimer".
    |
    | role="alertdialog" : indique aux lecteurs d'écran qu'il s'agit
    |   d'une boîte de dialogue nécessitant une action de l'utilisateur.
    | aria-labelledby    : pointe vers le texte de description du dialog.
    =========================================================================
    -->
    <div id="delete-confirm-bar" class="delete-confirm-bar"
         role="alertdialog" aria-labelledby="delete-confirm-label">

        <!-- Texte de confirmation avec le nom de la cible en rouge -->
        <p class="delete-confirm-text" id="delete-confirm-label">
            Supprimer <strong id="delete-confirm-name"></strong> ?
            Cette action est irréversible.
        </p>

        <!-- Bouton Annuler : referme le bandeau sans rien faire -->
        <button id="delete-cancel-btn" type="button" class="btn">
            Annuler
        </button>

        <!-- Bouton Confirmer : déclenche la requête de suppression -->
        <button id="delete-confirm-btn" type="button" class="btn-delete-confirm">
            Confirmer la suppression
        </button>
    </div>

    <!--
    -------------------------------------------------------------------------
    | Système de toasts
    -------------------------------------------------------------------------
    | scripts/toast.js contient uniquement la fonction showToast().
    | Il est séparé de index.js (trop lourd — FullCalendar, modales...)
    | pour pouvoir être chargé sur des pages légères comme celle-ci.
    -------------------------------------------------------------------------
    -->
    <script src="scripts/toast.js"></script>

    <!--
    -------------------------------------------------------------------------
    | Container des toasts
    -------------------------------------------------------------------------
    | Ce div est le point d’injection des notifications visuelles.
    | Le CSS (calendars.css) le positionne en fixed en bas à droite.
    | Note : toast.js le crée automatiquement si ce div est absent,
    | mais le déclarer ici est plus propre et évite tout décalage visuel.
    -------------------------------------------------------------------------
    -->
    <div id="toast-container" class="toast-container" aria-live="polite" aria-atomic="true"></div>

    <script>
        /*
        ----------------------------------------------------------------------
        | Envoi AJAX du formulaire d’ajout utilisateur
        ----------------------------------------------------------------------
        | Point très important :
        | On ajoute credentials: "same-origin" pour que le navigateur envoie
        | bien le cookie de session PHP à Users/add_user.php.
        |
        | Sans cette ligne, l’API peut répondre "Non connecté" même si la page
        | users.php elle-même est bien ouverte avec une session active.
        ----------------------------------------------------------------------
        */
        document.getElementById("form-add-user").addEventListener("submit", async function (e) {
            e.preventDefault();

            // Récupération et nettoyage simple des champs du formulaire
            const full_name = document.getElementById("full_name").value.trim();
            const email    = document.getElementById("email").value.trim();
            const password = document.getElementById("password").value.trim();

            // Récupération du rôle choisi dans le <select>
            // La valeur sera soit "user" soit "admin"
            const role = document.getElementById("role").value;

            // Validation front minimale avant envoi — toast rouge si champs vides
            if (full_name === "" || email === "") {
                showToast("Le nom complet et l’adresse e-mail sont obligatoires.", "error");
                return;
            }

            try {
                const res = await fetch("Users/add_user.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },

                    /*
                    ----------------------------------------------------------
                    | Correction essentielle
                    ----------------------------------------------------------
                    | Cette option force l’envoi du cookie de session PHP
                    | sur une requête vers le même site.
                    |
                    | Résultat :
                    | - Users/add_user.php reçoit bien la session
                    | - $_SESSION[‘user_id’] est disponible
                    | - l’API ne répond plus "Non connecté"
                    ----------------------------------------------------------
                    */
                    credentials: "same-origin",

                    // On inclut "role" dans le corps JSON envoyé au backend
                    body: JSON.stringify({
                        full_name,
                        email,
                        password,
                        role
                    })
                });

                // On essaie de lire la réponse JSON
                const data = await res.json();

                // Si l’API répond success = true — toast vert de confirmation
                if (data.success) {
                    showToast("Utilisateur ajouté avec succès.", "success");
                    // Rechargement après un court délai pour laisser le toast s’afficher
                    setTimeout(() => window.location.reload(), 1200);
                    return;
                }

                // Sinon on affiche le message d’erreur renvoyé par le backend — toast rouge
                showToast(data.error || "Une erreur est survenue.", "error");
            } catch (error) {
                console.error("Erreur add_user :", error);
                // Erreur réseau ou réponse non-JSON — toast rouge générique
                showToast("Erreur réseau ou serveur.", "error");
            }
        });
    </script>

    <script>
        /*
        ======================================================================
        | SUPPRESSION D'UTILISATEUR
        ======================================================================
        | Ce bloc gère toute la logique de suppression :
        |   1. Clic sur un bouton "Supprimer" → affiche le bandeau
        |   2. Clic sur "Annuler"             → cache le bandeau
        |   3. Clic sur "Confirmer"           → envoie la requête à l'API
        |   4. Succès                         → retire la ligne du DOM + toast
        |   5. Erreur                         → toast rouge avec le message
        ======================================================================
        */

        /*
        ----------------------------------------------------------------------
        | Références aux éléments du bandeau de confirmation
        ----------------------------------------------------------------------
        | On les récupère une seule fois ici pour éviter de les chercher
        | dans le DOM à chaque clic.
        ----------------------------------------------------------------------
        */
        const confirmBar  = document.getElementById("delete-confirm-bar");
        const confirmName = document.getElementById("delete-confirm-name");
        const confirmBtn  = document.getElementById("delete-confirm-btn");
        const cancelBtn   = document.getElementById("delete-cancel-btn");

        /*
        ----------------------------------------------------------------------
        | Variables d'état de la suppression en cours
        ----------------------------------------------------------------------
        | pendingDeleteId  : id de l'utilisateur dont la suppression est
        |                    en attente de confirmation (null = aucun)
        | pendingDeleteRow : référence à l'élément <tr> de cet utilisateur,
        |                    pour pouvoir le retirer du DOM après suppression
        ----------------------------------------------------------------------
        */
        let pendingDeleteId  = null;
        let pendingDeleteRow = null;

        /*
        ----------------------------------------------------------------------
        | Délégation d'événements sur le tableau
        ----------------------------------------------------------------------
        | Au lieu d'ajouter un listener sur chaque bouton "Supprimer",
        | on en place UN SEUL sur le tbody.
        |
        | Quand un clic se produit n'importe où dans le tbody, on vérifie
        | si l'élément cliqué (ou un de ses parents) est un .btn-delete.
        |
        | Avantages :
        | - Un seul listener = meilleure performance
        | - Fonctionne même si des lignes sont ajoutées dynamiquement
        | - Technique classique et appréciée en entretien technique BTS
        ----------------------------------------------------------------------
        */
        document.getElementById("users-table-body").addEventListener("click", function (e) {

            // e.target.closest(".btn-delete") remonte l'arbre DOM depuis
            // l'élément cliqué jusqu'à trouver un ancêtre avec la classe .btn-delete
            const btn = e.target.closest(".btn-delete");

            // Si le clic n'était pas sur un bouton de suppression, on ignore
            if (!btn) return;

            // On mémorise l'id et la ligne de la cible
            pendingDeleteId  = parseInt(btn.dataset.id, 10);
            pendingDeleteRow = btn.closest("tr");

            // On affiche le nom dans le bandeau de confirmation
            confirmName.textContent = btn.dataset.name;

            // On fait glisser le bandeau vers le haut (classe visible → CSS transform)
            confirmBar.classList.add("visible");
        });

        /*
        ----------------------------------------------------------------------
        | Annulation de la suppression
        ----------------------------------------------------------------------
        | On réinitialise les variables d'état et on cache le bandeau.
        ----------------------------------------------------------------------
        */
        cancelBtn.addEventListener("click", function () {
            pendingDeleteId  = null;
            pendingDeleteRow = null;
            confirmBar.classList.remove("visible");
        });

        /*
        ----------------------------------------------------------------------
        | Confirmation et envoi de la requête de suppression
        ----------------------------------------------------------------------
        */
        confirmBtn.addEventListener("click", async function () {

            // Sécurité : si aucune suppression n'est en attente, on ne fait rien
            if (!pendingDeleteId) return;

            /*
            | On désactive le bouton pendant la requête pour éviter
            | les doubles clics accidentels (ou intentionnels).
            | Le texte change aussi pour indiquer que ça travaille.
            */
            confirmBtn.disabled = true;
            confirmBtn.textContent = "Suppression…";

            try {
                const res = await fetch("Users/delete_user.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },

                    /*
                    | credentials: "same-origin" est obligatoire pour que
                    | le cookie de session PHP soit envoyé avec la requête.
                    | Sans ça, l'API répondrait "Non connecté" (401).
                    */
                    credentials: "same-origin",

                    body: JSON.stringify({ id: pendingDeleteId })
                });

                const data = await res.json();

                if (data.success) {
                    /*
                    | Succès : on retire la ligne du DOM directement.
                    | C'est plus fluide qu'un rechargement complet de la page.
                    |
                    | Si le tbody est maintenant vide (plus aucun utilisateur),
                    | on ajoute une ligne "Aucun utilisateur" pour éviter
                    | d'afficher un tableau vide sans indication.
                    */
                    if (pendingDeleteRow) {
                        const tbody = pendingDeleteRow.closest("tbody");
                        pendingDeleteRow.remove();

                        // Vérification : le tableau est-il maintenant vide ?
                        if (tbody.querySelectorAll("tr").length === 0) {
                            const emptyRow = document.createElement("tr");
                            emptyRow.innerHTML =
                                '<td colspan="6" style="text-align:center;color:var(--muted);padding:20px;">' +
                                'Aucun utilisateur.' +
                                '</td>';
                            tbody.appendChild(emptyRow);
                        }
                    }

                    showToast("Utilisateur supprimé.", "success");

                } else {
                    // L'API a répondu avec une erreur métier (403, 404, etc.)
                    showToast(data.error || "Une erreur est survenue.", "error");
                }

            } catch (err) {
                // Erreur réseau ou réponse non-JSON
                console.error("Erreur delete_user :", err);
                showToast("Erreur réseau ou serveur.", "error");
            } finally {
                /*
                | Le bloc finally s'exécute toujours, même en cas d'erreur.
                | On réinitialise le bouton et on cache le bandeau dans tous les cas.
                */
                confirmBtn.disabled = false;
                confirmBtn.textContent = "Confirmer la suppression";
                confirmBar.classList.remove("visible");
                pendingDeleteId  = null;
                pendingDeleteRow = null;
            }
        });
    </script>

    <script>
        /*
        ======================================================================
        | TRANSFERT DE CALENDRIERS
        ======================================================================
        | Ce bloc gère toute la logique du transfert :
        |   1. Clic sur "Transférer"  → peuple le <select> et affiche le panneau
        |   2. Clic sur "Annuler"     → cache le panneau sans rien faire
        |   3. Clic sur "Confirmer"   → envoie la requête à l'API
        |   4. Succès                 → toast vert + rechargement de la page
        |   5. Erreur                 → toast rouge avec le message de l'API
        ======================================================================
        */

        /*
        ----------------------------------------------------------------------
        | Liste des administrateurs — injectée depuis PHP
        ----------------------------------------------------------------------
        | PHP encode en JSON le tableau des admins (id + nom).
        | JS l'utilise pour peupler le <select> sans requête supplémentaire.
        |
        | json_encode() produit un tableau JSON sûr :
        | les caractères spéciaux sont automatiquement échappés.
        |
        | On filtre ici uniquement les admins (role = 'admin') car
        | le transfert ne peut se faire que vers un administrateur.
        ----------------------------------------------------------------------
        */
        const ALL_ADMINS = <?php
            $adminsData = array_values(array_filter(
                $users,
                fn(array $u): bool => $u['role'] === 'admin'
            ));
            echo json_encode(array_map(
                fn(array $u): array => [
                    'id'        => (int)$u['id'],
                    'full_name' => $u['full_name']
                ],
                $adminsData
            ));
        ?>;

        /*
        ----------------------------------------------------------------------
        | Références aux éléments du panneau de transfert
        ----------------------------------------------------------------------
        */
        const transferPanel      = document.getElementById("transfer-panel");
        const transferSourceName = document.getElementById("transfer-source-name");
        const transferCalCount   = document.getElementById("transfer-cal-count");
        const transferSelect     = document.getElementById("transfer-select");
        const transferConfirmBtn = document.getElementById("transfer-confirm-btn");
        const transferCancelBtn  = document.getElementById("transfer-cancel-btn");

        /*
        ----------------------------------------------------------------------
        | Variables d'état du transfert en cours
        ----------------------------------------------------------------------
        | pendingTransferId : id de l'utilisateur source (null = aucun)
        ----------------------------------------------------------------------
        */
        let pendingTransferId = null;

        /*
        ----------------------------------------------------------------------
        | Délégation d'événements sur le tbody pour les boutons "Transférer"
        ----------------------------------------------------------------------
        | Même technique que pour la suppression : un seul listener sur tbody.
        ----------------------------------------------------------------------
        */
        document.getElementById("users-table-body").addEventListener("click", function (e) {

            const btn = e.target.closest(".btn-transfer");
            if (!btn) return;

            // On mémorise l'id de la source
            pendingTransferId = parseInt(btn.dataset.id, 10);

            const sourceName  = btn.dataset.name;
            const calCount    = parseInt(btn.dataset.count, 10);

            // Affichage du nom et du compteur dans le panneau
            transferSourceName.textContent = sourceName;
            const label = calCount === 1 ? "1 calendrier" : calCount + " calendriers";
            transferCalCount.textContent   = label;

            /*
            | Peuplement du <select> avec les admins disponibles.
            |
            | On vide d'abord le select (cas où on clique sur un 2ème "Transférer"),
            | puis on ajoute une option par admin, en excluant l'utilisateur source
            | (il ne peut pas transférer ses calendriers vers lui-même).
            */
            transferSelect.innerHTML = "";

            const availableAdmins = ALL_ADMINS.filter(a => a.id !== pendingTransferId);

            if (availableAdmins.length === 0) {
                /*
                | Aucun admin disponible comme destination.
                | On désactive le bouton de confirmation et on l'indique.
                */
                const opt = document.createElement("option");
                opt.textContent = "Aucun administrateur disponible";
                opt.disabled    = true;
                transferSelect.appendChild(opt);
                transferConfirmBtn.disabled = true;
            } else {
                // On réactive le bouton au cas où il avait été désactivé
                transferConfirmBtn.disabled = false;

                availableAdmins.forEach(function (admin) {
                    const opt       = document.createElement("option");
                    opt.value       = admin.id;
                    opt.textContent = admin.full_name;
                    transferSelect.appendChild(opt);
                });
            }

            // Affichage du panneau (glissement depuis le bas)
            transferPanel.classList.add("visible");
        });

        /*
        ----------------------------------------------------------------------
        | Annulation du transfert
        ----------------------------------------------------------------------
        */
        transferCancelBtn.addEventListener("click", function () {
            pendingTransferId = null;
            transferPanel.classList.remove("visible");
        });

        /*
        ----------------------------------------------------------------------
        | Confirmation et envoi de la requête de transfert
        ----------------------------------------------------------------------
        */
        transferConfirmBtn.addEventListener("click", async function () {

            if (!pendingTransferId) return;

            const toId = parseInt(transferSelect.value, 10);
            if (!toId) return;

            // Désactivation anti-double clic
            transferConfirmBtn.disabled     = true;
            transferConfirmBtn.textContent  = "Transfert…";

            try {
                const res = await fetch("Users/transfer_calendars.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    credentials: "same-origin",
                    body: JSON.stringify({
                        from_id: pendingTransferId,
                        to_id:   toId
                    })
                });

                const data = await res.json();

                if (data.success) {
                    /*
                    | Succès : on utilise le champ "count" renvoyé par l'API
                    | pour afficher un message précis (ex: "2 calendriers transférés").
                    |
                    | On recharge la page après un court délai pour que le toast
                    | soit visible avant le rechargement.
                    | Après rechargement, le bouton "Transférer" aura disparu
                    | et le bouton "Supprimer" sera redevenu actif.
                    */
                    const count = data.count ?? 0;
                    const label = count === 1 ? "1 calendrier transféré" : count + " calendriers transférés";
                    showToast(label + ".", "success");
                    setTimeout(() => window.location.reload(), 1400);
                } else {
                    showToast(data.error || "Une erreur est survenue.", "error");
                }

            } catch (err) {
                console.error("Erreur transfer_calendars :", err);
                showToast("Erreur réseau ou serveur.", "error");
            } finally {
                // Réinitialisation dans tous les cas
                transferConfirmBtn.disabled    = false;
                transferConfirmBtn.textContent = "Confirmer le transfert";
                transferPanel.classList.remove("visible");
                pendingTransferId = null;
            }
        });
    </script>
</body>
</html>