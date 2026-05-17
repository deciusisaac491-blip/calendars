<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION["user_id"])) {
// Redirection vers la page de connexion principale (plus dev_login.php)
header("Location: login.php");
exit;
}

$userId = (int)$_SESSION["user_id"];
$calendarId = 1;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>CALENDARS</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

<link rel="stylesheet" href="css/calendars.css">
</head>
<body>
<header class="topbar">
<div class="brand">CALENDARS</div>

<div class="topbar-right">
<span class="user-badge">User #<?php echo $userId; ?></span>
<a class="btn" href="logout.php">Déconnexion</a>
</div>
</header>

<div class="layout">
<aside class="sidebar">
<div class="sidebar-header-row">
<div class="sidebar-title">Mes calendriers</div>
<button id="btn-add-cal" class="btn btn-primary btn-sm" type="button">+ Nouveau</button>
</div>

<div id="cal-list" class="cal-list"></div>

<div class="sidebar-sep"></div>

<div class="hint">
Règles :
<ul>
<li>Tu peux créer un évènement en cliquant une date.</li>
<li>Seul le créateur peut modifier ou supprimer un évènement.</li>
<li>Tu peux inviter des utilisateurs internes et des invités externes.</li>
</ul>
</div>
</aside>

<main class="content">
<div id="calendar" data-calendar-id="<?php echo (int)$calendarId; ?>"></div>
</main>
</div>

<div id="event-modal" class="modal hidden" aria-hidden="true">
<div class="modal-card modal-card-large modal-card-event">
<div class="modal-header">
<h2 id="modal-title">Nouvel évènement</h2>
<button type="button" class="icon-btn" id="btn-close-modal" aria-label="Fermer">✕</button>
</div>

<div class="modal-body modal-body-scrollable">
<input type="hidden" id="event-id">
<input type="hidden" id="event-client-updated-at">

<div class="form-group">
<label for="event-title">Titre</label>
<input type="text" id="event-title" maxlength="255" placeholder="Ex. Réunion équipe">
</div>

<div class="form-group">
<label for="event-description">Description</label>
<textarea id="event-description" rows="4" placeholder="Détails de l'évènement"></textarea>
</div>

<div class="form-group">
<label for="event-location">Lieu</label>
<input type="text" id="event-location" maxlength="255" placeholder="Ex. Salle A, Teams, Chez le client">
</div>

<div class="form-row two-cols">
<div class="form-group">
<label for="event-status">Statut</label>
<select id="event-status">
<option value="confirmed">Confirmé</option>
<option value="pending">En attente</option>
<option value="cancelled">Annulé</option>
</select>
</div>

<div class="form-group">
<label for="event-priority">Priorité</label>
<select id="event-priority">
<option value="low">Basse</option>
<option value="normal">Normale</option>
<option value="high">Haute</option>
</select>
</div>
</div>

<div class="form-group">
<label for="event-reminder">Rappel</label>
<select id="event-reminder">
<option value="">Aucun rappel</option>
<option value="5">5 min avant</option>
<option value="15">15 min avant</option>
<option value="30">30 min avant</option>
<option value="60">1 h avant</option>
<option value="1440">1 jour avant</option>
</select>
</div>

<div class="form-group">
<label for="event-internal-users">Participants internes</label>
<select id="event-internal-users" class="multi-select" multiple></select>
<small class="muted-text">
Maintiens Ctrl (ou Cmd sur Mac) pour sélectionner plusieurs utilisateurs.
</small>
</div>

<div class="form-group">
<label for="event-guest-emails">Invités externes (e-mails)</label>
<textarea
id="event-guest-emails"
rows="4"
placeholder="client@entreprise.com&#10;prestataire@email.com"
></textarea>
<small class="muted-text">
Un e-mail par ligne, ou plusieurs séparés par des virgules / points-virgules.
</small>
</div>

<!-- Réponses des participants — rempli dynamiquement par renderParticipantResponses() -->
<!-- Visible uniquement en mode édition, si l'événement a au moins un participant -->
<div id="participants-responses" class="form-group hidden"></div>

<div class="form-row checkbox-row">
<label class="checkbox-label">
<input type="checkbox" id="event-all-day">
<span>Toute la journée</span>
</label>
</div>

<div class="form-row two-cols">
<div class="form-group">
<label for="event-start-date">Date début</label>
<input type="date" id="event-start-date">
</div>

<div class="form-group time-group" id="start-time-group">
<label for="event-start-time">Heure début</label>
<input type="time" id="event-start-time">
</div>
</div>

<div class="form-row two-cols">
<div class="form-group">
<label for="event-end-date">Date fin</label>
<input type="date" id="event-end-date">
</div>

<div class="form-group time-group" id="end-time-group">
<label for="event-end-time">Heure fin</label>
<input type="time" id="event-end-time">
</div>
</div>

<div class="form-group">
<label for="event-color">Couleur de l'évènement</label>
<div class="color-row">
<input type="color" id="event-color" value="#2d9cdb">
<span class="muted-text">Choisis une couleur visible dans le calendrier</span>
</div>
</div>
</div>

<div class="modal-footer">
<button type="button" class="btn" id="btn-cancel-event">Annuler</button>
<button type="button" class="btn danger" id="btn-delete-event">Supprimer</button>
<button type="button" class="btn btn-primary" id="btn-save-event">Enregistrer</button>
</div>
</div>
</div>

<div id="calendar-modal" class="modal hidden" aria-hidden="true">
<div class="modal-card modal-card-small modal-card-calendar">
<div class="modal-header">
<h2 id="calendar-modal-title">Nouveau calendrier</h2>
<button type="button" class="icon-btn" id="btn-close-calendar-modal" aria-label="Fermer">✕</button>
</div>

<div class="modal-body modal-body-scrollable">
<input type="hidden" id="calendar-id">

<div class="form-group">
<label for="calendar-name">Nom du calendrier</label>
<input type="text" id="calendar-name" maxlength="100" placeholder="Ex. Planning équipe">
</div>

<div class="form-group">
<label for="calendar-color">Couleur du calendrier</label>
<div class="color-row">
<input type="color" id="calendar-color" value="#2d9cdb">
<span class="muted-text">Cette couleur sera visible dans la liste des calendriers</span>
</div>
</div>

<p class="muted-text small-text">
Conseil : choisis une couleur différente pour distinguer rapidement tes calendriers.
</p>
</div>

<div class="modal-footer">
<button type="button" class="btn" id="btn-cancel-calendar">Annuler</button>
<button type="button" class="btn danger" id="btn-delete-calendar">Supprimer</button>
<button type="button" class="btn btn-primary" id="btn-save-calendar">Enregistrer</button>
</div>
</div>
</div>

<div id="confirm-modal" class="modal hidden" aria-hidden="true">
<div class="modal-card modal-card-confirm">
<div class="modal-header">
<h2 id="confirm-title">Confirmation</h2>
<button type="button" class="icon-btn" id="btn-close-confirm-modal" aria-label="Fermer">✕</button>
</div>

<div class="modal-body">
<p id="confirm-message" class="confirm-message">
Es-tu sûr de vouloir continuer ?
</p>
</div>

<div class="modal-footer">
<button type="button" class="btn" id="btn-cancel-confirm">Annuler</button>
<button type="button" class="btn danger" id="btn-confirm-action">Confirmer</button>
</div>
</div>
</div>

<div id="toast-container" class="toast-container" aria-live="polite" aria-atomic="true"></div>

<script src="scripts/sidebar.js"></script>
<script src="scripts/index.js"></script>
</body>
</html>
