/* ========================================================================
scripts/index.js
------------------------------------------------------------------------
Fichier principal du calendrier.
Gère :
- FullCalendar
- modales
- toasts
- confirmation
- participants internes
- invités externes
======================================================================== */

console.log("index.js chargé");

/* ------------------------------------------------------------------------
Variables globales
------------------------------------------------------------------------ */
let activeCalendarId = null;
let appCalendar = null;
let pendingConfirmAction = null;
let cachedUsers = [];

/* ========================================================================
TOASTS
======================================================================== */

/**
* Affiche une notification.
*/
function showToast(message, type = "info", duration = 3200) {
const container = document.getElementById("toast-container");
if (!container) return;

const toast = document.createElement("div");
toast.className = `toast toast-${type}`;

const icon = document.createElement("div");
icon.className = "toast-icon";
icon.textContent =
type === "success" ? "✓" :
type === "error" ? "!" :
"i";

const content = document.createElement("div");
content.className = "toast-content";
content.textContent = message;

const closeBtn = document.createElement("button");
closeBtn.type = "button";
closeBtn.className = "toast-close";
closeBtn.setAttribute("aria-label", "Fermer la notification");
closeBtn.textContent = "✕";

let removed = false;

const removeToast = () => {
if (removed) return;
removed = true;
toast.classList.add("toast-hide");
setTimeout(() => toast.remove(), 220);
};

closeBtn.addEventListener("click", removeToast);

toast.appendChild(icon);
toast.appendChild(content);
toast.appendChild(closeBtn);
container.appendChild(toast);

requestAnimationFrame(() => {
toast.classList.add("toast-show");
});

setTimeout(removeToast, duration);
}

window.showToast = showToast;

/* ========================================================================
RÉSULTATS D'ENVOI D'E-MAILS
======================================================================== */

/**
 * Analyse le tableau mail_results renvoyé par le backend
 * et affiche un toast secondaire adapté à la situation.
 *
 * Cette fonction est appelée après chaque action (create, update, delete)
 * pour informer l'utilisateur du résultat des envois d'e-mails.
 *
 * Cas possibles :
 *
 *   1. mail_results absent ou vide []
 *      → Aucun e-mail n'a été tenté (modification mineure ou aucun participant)
 *      → On n'affiche rien : le toast principal suffit.
 *
 *   2. Tous les envois réussis
 *      → Toast vert : "2 e-mails envoyés avec succès."
 *
 *   3. Certains envois ont échoué (réussis partiels)
 *      → Toast rouge : "2 e-mail(s) envoyé(s), 1 échec(s). Vérifie mail_debug.log."
 *
 *   4. Tous les envois ont échoué
 *      → Toast rouge : "Aucun e-mail n'a pu être envoyé (2 erreur(s))."
 *
 * @param {Array|undefined} mailResults - Tableau mail_results issu de la réponse backend
 */
function handleMailResults(mailResults) {
  // Cas 1 : tableau absent ou vide → rien à afficher
  // Cela couvre les modifications mineures (titre, couleur…)
  // et les événements sans participant ni invité.
  if (!Array.isArray(mailResults) || mailResults.length === 0) {
    return;
  }

  const total     = mailResults.length;
  const successes = mailResults.filter(r => r.success === true).length;
  const failures  = total - successes;

  if (failures === 0) {
    // Cas 2 : tous les e-mails ont été envoyés avec succès
    const label = total === 1 ? "e-mail envoyé" : "e-mails envoyés";
    showToast(`${total} ${label} avec succès.`, "success", 3200);

  } else if (successes > 0) {
    // Cas 3 : certains envois ont échoué (résultat partiel)
    showToast(
      `${successes} e-mail(s) envoyé(s), ${failures} échec(s). Vérifie mail_debug.log.`,
      "error",
      5000
    );

  } else {
    // Cas 4 : aucun e-mail n'a pu être envoyé
    showToast(
      `Aucun e-mail n'a pu être envoyé (${failures} erreur(s)). Vérifie mail_debug.log.`,
      "error",
      5000
    );
  }
}

/* ========================================================================
CONFIRMATION
======================================================================== */

function openConfirmModal({
title = "Confirmation",
message = "Es-tu sûr de vouloir continuer ?",
confirmText = "Confirmer",
onConfirm = null
}) {
const modal = document.getElementById("confirm-modal");
const titleEl = document.getElementById("confirm-title");
const messageEl = document.getElementById("confirm-message");
const confirmBtn = document.getElementById("btn-confirm-action");

titleEl.textContent = title;
messageEl.textContent = message;
confirmBtn.textContent = confirmText;

pendingConfirmAction = typeof onConfirm === "function" ? onConfirm : null;

modal.classList.remove("hidden");
modal.setAttribute("aria-hidden", "false");
}

function closeConfirmModal() {
const modal = document.getElementById("confirm-modal");
modal.classList.add("hidden");
modal.setAttribute("aria-hidden", "true");
pendingConfirmAction = null;
}

async function runConfirmAction() {
const action = pendingConfirmAction;
closeConfirmModal();

if (typeof action === "function") {
await action();
}
}

window.openConfirmModal = openConfirmModal;
window.closeConfirmModal = closeConfirmModal;

/* ========================================================================
HELPERS DATE / HEURE
======================================================================== */

function formatDateForInput(dateObj) {
const year = dateObj.getFullYear();
const month = String(dateObj.getMonth() + 1).padStart(2, "0");
const day = String(dateObj.getDate()).padStart(2, "0");
return `${year}-${month}-${day}`;
}

function formatTimeForInput(dateObj) {
const hours = String(dateObj.getHours()).padStart(2, "0");
const minutes = String(dateObj.getMinutes()).padStart(2, "0");
return `${hours}:${minutes}`;
}

function localDateTimeToUtcIso(dateStr, timeStr) {
if (!dateStr) return null;

const safeTime = timeStr && timeStr.trim() !== "" ? timeStr : "00:00";
const local = new Date(`${dateStr}T${safeTime}:00`);

if (Number.isNaN(local.getTime())) {
return null;
}

return local.toISOString();
}

function toAllDayDate(dateObj) {
if (!(dateObj instanceof Date) || Number.isNaN(dateObj.getTime())) {
return null;
}
return formatDateForInput(dateObj);
}

function getInclusiveAllDayEndFromExclusive(endDateObj, fallbackStartObj) {
if (endDateObj instanceof Date && !Number.isNaN(endDateObj.getTime())) {
const inclusive = new Date(endDateObj.getTime());
inclusive.setDate(inclusive.getDate() - 1);
return formatDateForInput(inclusive);
}

if (fallbackStartObj instanceof Date && !Number.isNaN(fallbackStartObj.getTime())) {
return formatDateForInput(fallbackStartObj);
}

return null;
}

/* ========================================================================
HELPERS API
======================================================================== */

function apiErrorMessage(data, res, label) {
return data?.error || `${label} (${res.status})`;
}

async function apiLoadEvents(calendarId, fetchInfo) {
const params = new URLSearchParams({
calendar_id: String(calendarId),
start: fetchInfo.startStr,
end: fetchInfo.endStr
});

const res = await fetch(`backend/Event/load_event.php?${params.toString()}`, {
credentials: "same-origin"
});

const data = await res.json().catch(() => null);

if (!res.ok) {
throw new Error(apiErrorMessage(data, res, "Erreur load_event"));
}

return data.events || [];
}

async function apiSaveEvent(payload) {
const res = await fetch("backend/Event/save_event.php", {
method: "POST",
headers: { "Content-Type": "application/json" },
credentials: "same-origin",
body: JSON.stringify(payload)
});

const data = await res.json().catch(() => null);

if (!res.ok) {
throw new Error(apiErrorMessage(data, res, "Erreur save_event"));
}

return data;
}

async function apiLoadUsers() {
const res = await fetch("backend/Event/users_list.php", {
method: "GET",
credentials: "same-origin"
});

const data = await res.json().catch(() => null);

if (!res.ok) {
throw new Error(apiErrorMessage(data, res, "Erreur users_list"));
}

return data.users || [];
}

/* ========================================================================
RAPPEL
======================================================================== */

function getReminderLabel(minutes) {
const value = Number(minutes);

if (!Number.isFinite(value) || value <= 0) return "";
if (value === 5) return "5 min avant";
if (value === 15) return "15 min avant";
if (value === 30) return "30 min avant";
if (value === 60) return "1 h avant";
if (value === 1440) return "1 jour avant";

return `${value} min avant`;
}

/* ========================================================================
PARTICIPANTS / INVITÉS
======================================================================== */

/**
* Charge les utilisateurs internes depuis le backend
* puis remplit la liste multiple.
*/
async function loadInternalUsers(selectedIds = []) {
cachedUsers = await apiLoadUsers();
renderInternalUsersSelect(selectedIds);
}

/**
* Remplit la liste multiple des participants internes.
*/
function renderInternalUsersSelect(selectedIds = []) {
const select = document.getElementById("event-internal-users");
if (!select) return;

select.innerHTML = "";

cachedUsers.forEach((user) => {
const option = document.createElement("option");
option.value = String(user.id);
option.textContent = user.label;
option.selected = selectedIds.includes(Number(user.id));
select.appendChild(option);
});
}

/**
* Échappe les caractères HTML pour éviter le XSS lors de l'injection innerHTML.
*/
function escapeHtml(str) {
return String(str)
  .replace(/&/g, "&amp;")
  .replace(/</g, "&lt;")
  .replace(/>/g, "&gt;")
  .replace(/"/g, "&quot;");
}

/**
* Affiche les réponses des participants dans le formulaire d'édition.
*
* Paramètres :
*   internalParticipants — tableau d'objets {user_id, full_name, email, response}
*                          (vient de extendedProps.internal_participants)
*   guestList            — tableau d'objets {email, response}
*                          (vient de extendedProps.guest_list)
*
* La fonction remplit le div #participants-responses avec une liste de noms /
* e-mails accompagnés d'un badge coloré indiquant la réponse de chaque invité :
*   ● Vert   "Accepté"    → response === 'accepted'
*   ● Rouge  "Refusé"     → response === 'declined'
*   ● Gris   "En attente" → response === 'pending' (valeur par défaut)
*
* Si aucun participant n'est trouvé, le conteneur reste masqué.
*/
function renderParticipantResponses(internalParticipants = [], guestList = []) {
const container = document.getElementById("participants-responses");
if (!container) return;

/*
| On fusionne les deux sources en un tableau unifié {label, response}
| pour pouvoir boucler une seule fois.
*/
const all = [
  ...internalParticipants.map((p) => ({
    label: p.full_name || p.email || "—",
    response: p.response || "pending",
  })),
  ...guestList.map((g) => ({
    label: g.email || "—",
    response: g.response || "pending",
  })),
];

if (all.length === 0) {
  container.classList.add("hidden");
  container.innerHTML = "";
  return;
}

const LABELS  = { accepted: "Accepté", declined: "Refusé", pending: "En attente" };
const CLASSES = { accepted: "badge-accepted", declined: "badge-declined", pending: "badge-pending" };

const rows = all
  .map((p) => {
    const badgeLabel = LABELS[p.response]  || "En attente";
    const badgeClass = CLASSES[p.response] || "badge-pending";
    return `<li class="response-row">
      <span class="response-name">${escapeHtml(p.label)}</span>
      <span class="badge ${badgeClass}">${badgeLabel}</span>
    </li>`;
  })
  .join("");

container.innerHTML = `
  <div class="response-list-label">Réponses des participants</div>
  <ul class="response-list">${rows}</ul>
`;
container.classList.remove("hidden");
}

/**
* Retourne les IDs sélectionnés dans la liste multiple.
*/
function getSelectedInternalUserIds() {
const select = document.getElementById("event-internal-users");
if (!select) return [];

return Array.from(select.selectedOptions)
.map((opt) => parseInt(opt.value, 10))
.filter((id) => Number.isInteger(id) && id > 0);
}

/**
* Analyse le champ des invités externes.
* On autorise :
* - un e-mail par ligne
* - ou séparés par virgules / points-virgules
*/
function parseGuestEmails(rawText) {
return Array.from(
new Set(
String(rawText || "")
.split(/[\n,;]+/g)
.map((s) => s.trim().toLowerCase())
.filter((s) => s.length > 0)
)
);
}

/**
* Validation légère e-mail côté front.
*/
function isValidEmail(email) {
return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

/* ========================================================================
MODALE ÉVÉNEMENT
======================================================================== */

function getModalElements() {
return {
modal: document.getElementById("event-modal"),
title: document.getElementById("modal-title"),
eventId: document.getElementById("event-id"),
clientUpdatedAt: document.getElementById("event-client-updated-at"),
eventTitle: document.getElementById("event-title"),
eventDescription: document.getElementById("event-description"),
eventLocation: document.getElementById("event-location"),
eventStatus: document.getElementById("event-status"),
eventPriority: document.getElementById("event-priority"),
eventReminder: document.getElementById("event-reminder"),
eventInternalUsers: document.getElementById("event-internal-users"),
eventGuestEmails: document.getElementById("event-guest-emails"),
eventAllDay: document.getElementById("event-all-day"),
eventStartDate: document.getElementById("event-start-date"),
eventStartTime: document.getElementById("event-start-time"),
eventEndDate: document.getElementById("event-end-date"),
eventEndTime: document.getElementById("event-end-time"),
eventColor: document.getElementById("event-color"),
deleteBtn: document.getElementById("btn-delete-event"),
startTimeGroup: document.getElementById("start-time-group"),
endTimeGroup: document.getElementById("end-time-group")
};
}

function updateTimeInputsState() {
const {
eventAllDay,
eventStartTime,
eventEndTime,
startTimeGroup,
endTimeGroup
} = getModalElements();

const isAllDay = eventAllDay.checked;
eventStartTime.disabled = isAllDay;
eventEndTime.disabled = isAllDay;

if (isAllDay) {
startTimeGroup.classList.add("hidden-time");
endTimeGroup.classList.add("hidden-time");
} else {
startTimeGroup.classList.remove("hidden-time");
endTimeGroup.classList.remove("hidden-time");
}
}

function openCreateModal(info) {
const els = getModalElements();
const clickedDate = info.date instanceof Date ? info.date : new Date(info.dateStr);

els.title.textContent = "Nouvel évènement";
els.eventId.value = "";
els.clientUpdatedAt.value = "";
els.eventTitle.value = "";
els.eventDescription.value = "";
els.eventLocation.value = "";
els.eventStatus.value = "confirmed";
els.eventPriority.value = "normal";
els.eventReminder.value = "";
els.eventGuestEmails.value = "";

// Aucun participant interne sélectionné à la création
renderInternalUsersSelect([]);

const hasTime = !info.allDay && info.date instanceof Date;
els.eventAllDay.checked = !hasTime;

els.eventStartDate.value = formatDateForInput(clickedDate);
els.eventEndDate.value = formatDateForInput(clickedDate);
els.eventStartTime.value = hasTime ? formatTimeForInput(clickedDate) : "09:00";

const endDate = new Date(clickedDate.getTime() + 60 * 60 * 1000);
els.eventEndTime.value = hasTime ? formatTimeForInput(endDate) : "10:00";

els.eventColor.value = "#2d9cdb";
els.deleteBtn.style.display = "none";

updateTimeInputsState();

els.modal.classList.remove("hidden");
els.modal.setAttribute("aria-hidden", "false");
els.eventTitle.focus();
}

function openEditModal(event) {
const els = getModalElements();

const start = event.start ? new Date(event.start) : new Date();
const end = event.end ? new Date(event.end) : new Date(start.getTime() + 60 * 60 * 1000);

els.title.textContent = "Modifier l'évènement";
els.eventId.value = String(event.id || "");
els.clientUpdatedAt.value = event.extendedProps?.client_updated_at || "";
els.eventTitle.value = event.extendedProps?.raw_title || event.title || "";
els.eventDescription.value = event.extendedProps?.description || "";
els.eventLocation.value = event.extendedProps?.location || "";
els.eventStatus.value = event.extendedProps?.status || "confirmed";
els.eventPriority.value = event.extendedProps?.priority || "normal";
els.eventReminder.value = event.extendedProps?.reminder_minutes != null
? String(event.extendedProps.reminder_minutes)
: "";
els.eventGuestEmails.value = Array.isArray(event.extendedProps?.guest_emails)
? event.extendedProps.guest_emails.join("\n")
: "";

const selectedIds = Array.isArray(event.extendedProps?.internal_user_ids)
? event.extendedProps.internal_user_ids.map((id) => Number(id))
: [];
renderInternalUsersSelect(selectedIds);

/*
| Affiche les badges de réponse (Accepté / Refusé / En attente) pour chaque
| participant. On passe les deux tableaux issus des extendedProps :
|   internal_participants → {user_id, full_name, email, response}
|   guest_list            → {email, response}
| Si vides, renderParticipantResponses() masque le conteneur.
*/
renderParticipantResponses(
  event.extendedProps?.internal_participants || [],
  event.extendedProps?.guest_list            || []
);

els.eventAllDay.checked = !!event.allDay;
els.eventStartDate.value = formatDateForInput(start);

if (event.allDay) {
els.eventEndDate.value = getInclusiveAllDayEndFromExclusive(event.end, event.start) || formatDateForInput(start);
} else {
els.eventEndDate.value = formatDateForInput(end);
}

els.eventStartTime.value = formatTimeForInput(start);
els.eventEndTime.value = formatTimeForInput(end);
els.eventColor.value = event.backgroundColor || event.extendedProps?.color || "#2d9cdb";
els.deleteBtn.style.display = "inline-block";

updateTimeInputsState();

els.modal.classList.remove("hidden");
els.modal.setAttribute("aria-hidden", "false");
els.eventTitle.focus();
}

function closeModal() {
const { modal } = getModalElements();
modal.classList.add("hidden");
modal.setAttribute("aria-hidden", "true");

/*
| Réinitialise le bloc des réponses pour qu'il ne soit pas visible
| lors de la prochaine ouverture du modal en mode "Créer".
*/
const responsesDiv = document.getElementById("participants-responses");
if (responsesDiv) {
  responsesDiv.innerHTML = "";
  responsesDiv.classList.add("hidden");
}
}

window.closeModal = closeModal;

/* ========================================================================
SAUVEGARDE ÉVÉNEMENT
======================================================================== */

async function saveModalEvent() {
const els = getModalElements();

const eventId = parseInt(els.eventId.value || "0", 10);
const title = els.eventTitle.value.trim();
const description = els.eventDescription.value.trim();
const location = els.eventLocation.value.trim();
const status = els.eventStatus.value;
const priority = els.eventPriority.value;
const reminderMinutes = els.eventReminder.value === "" ? null : parseInt(els.eventReminder.value, 10);
const allDay = els.eventAllDay.checked ? 1 : 0;
const color = els.eventColor.value || "#2d9cdb";

// NOUVEAU : participants / invités
const internalUserIds = getSelectedInternalUserIds();
const guestEmails = parseGuestEmails(els.eventGuestEmails.value);

if (title === "") {
showToast("Le titre est obligatoire.", "error");
els.eventTitle.focus();
return;
}

for (const email of guestEmails) {
if (!isValidEmail(email)) {
showToast(`Adresse e-mail invalide : ${email}`, "error", 4200);
return;
}
}

if (!els.eventStartDate.value) {
showToast("La date de début est obligatoire.", "error");
return;
}

if (!els.eventEndDate.value) {
els.eventEndDate.value = els.eventStartDate.value;
}

let start = null;
let end = null;

if (allDay) {
start = els.eventStartDate.value;
end = els.eventEndDate.value;
} else {
if (!els.eventStartTime.value) {
showToast("L'heure de début est obligatoire.", "error");
return;
}

if (!els.eventEndTime.value) {
showToast("L'heure de fin est obligatoire.", "error");
return;
}

const startIso = localDateTimeToUtcIso(els.eventStartDate.value, els.eventStartTime.value);
const endIso = localDateTimeToUtcIso(els.eventEndDate.value, els.eventEndTime.value);

if (!startIso) {
showToast("Date/heure de début invalide.", "error");
return;
}

if (!endIso) {
showToast("Date/heure de fin invalide.", "error");
return;
}

if (new Date(endIso).getTime() < new Date(startIso).getTime()) {
showToast("La date de fin doit être après la date de début.", "error");
return;
}

start = startIso;
end = endIso;
}

const payload = {
action: eventId > 0 ? "update" : "create",
calendar_id: activeCalendarId,
event_id: eventId > 0 ? eventId : undefined,
title,
description,
location,
status,
priority,
reminder_minutes: reminderMinutes,

// NOUVEAU
internal_user_ids: internalUserIds,
guest_emails: guestEmails,

start,
end,
all_day: allDay,
color,
client_updated_at: els.clientUpdatedAt.value || null
};

try {
// On capture la réponse complète du backend (contient mail_results)
const result = await apiSaveEvent(payload);
closeModal();
appCalendar.refetchEvents();
// Toast principal : confirmation de la sauvegarde (inchangé)
showToast(
eventId > 0 ? "Évènement mis à jour avec succès." : "Évènement créé avec succès.",
"success"
);
// Toast secondaire : résumé des envois d'e-mails
// → vide si modification mineure, rempli si modification majeure avec participants
handleMailResults(result?.mail_results);
} catch (e) {
showToast(e.message, "error", 4200);
}
}

window.saveEvent = saveModalEvent;

/* ========================================================================
SUPPRESSION
======================================================================== */

async function reallyDeleteEvent(eventId) {
// On capture la réponse pour lire les résultats d'envoi d'e-mails d'annulation
const result = await apiSaveEvent({
action: "delete",
calendar_id: activeCalendarId,
event_id: eventId
});

closeModal();
appCalendar.refetchEvents();
// Toast principal : confirmation de la suppression (inchangé)
showToast("Évènement supprimé.", "success");
// Toast secondaire : résumé des e-mails d'annulation envoyés aux participants
handleMailResults(result?.mail_results);
}

async function deleteModalEvent() {
const els = getModalElements();
const eventId = parseInt(els.eventId.value || "0", 10);
if (eventId <= 0) return;

openConfirmModal({
title: "Supprimer l'évènement",
message: "Es-tu sûr de vouloir supprimer cet évènement ? Cette action est définitive.",
confirmText: "Supprimer",
onConfirm: async () => {
try {
await reallyDeleteEvent(eventId);
} catch (e) {
showToast(e.message, "error", 4200);
}
}
});
}

window.deleteEvent = deleteModalEvent;

/* ========================================================================
INITIALISATION
======================================================================== */

document.addEventListener("DOMContentLoaded", async () => {
const calendarEl = document.getElementById("calendar");
activeCalendarId = parseInt(calendarEl.dataset.calendarId, 10);

if (!activeCalendarId || Number.isNaN(activeCalendarId)) {
console.error("calendar_id manquant. Vérifie data-calendar-id dans index.php");
return;
}

// Charge les utilisateurs internes une seule fois au départ
try {
await loadInternalUsers([]);
} catch (e) {
console.error(e);
showToast("Impossible de charger la liste des utilisateurs internes.", "error", 4200);
}

appCalendar = new FullCalendar.Calendar(calendarEl, {
initialView: "dayGridMonth",
locale: "fr",
timeZone: "local",
firstDay: 1,
height: "auto",
contentHeight: "auto",
expandRows: true,
stickyHeaderDates: true,
nowIndicator: true,
editable: true,
selectable: true,
eventStartEditable: true,
eventDurationEditable: true,
allDaySlot: true,
slotMinTime: "06:00:00",
slotMaxTime: "23:00:00",
slotDuration: "00:30:00",
slotLabelInterval: "01:00:00",
scrollTime: "07:00:00",
snapDuration: "00:15:00",
eventMinHeight: 24,
dayMaxEventRows: 4,
businessHours: {
daysOfWeek: [1, 2, 3, 4, 5],
startTime: "08:00",
endTime: "18:00"
},
headerToolbar: {
left: "prev,next today",
center: "title",
right: "dayGridMonth,timeGridWeek,timeGridDay"
},
buttonText: {
today: "Aujourd'hui",
month: "Mois",
week: "Semaine",
day: "Jour"
},
views: {
dayGridMonth: {
dayMaxEventRows: 4,
moreLinkText: "plus"
},
timeGridWeek: {
allDayText: "Toute la journée"
},
timeGridDay: {
allDayText: "Toute la journée"
}
},
eventTimeFormat: {
hour: "2-digit",
minute: "2-digit",
hour12: false
},

events: async (fetchInfo, successCallback, failureCallback) => {
try {
const events = await apiLoadEvents(activeCalendarId, fetchInfo);
successCallback(events);
} catch (e) {
console.error(e);
showToast(e.message, "error", 4200);
failureCallback(e);
}
},

dateClick: (info) => openCreateModal(info),

eventClick: (info) => {
openEditModal(info.event);
},

eventDidMount: (info) => {
const location = info.event.extendedProps?.location || "";
const status   = info.event.extendedProps?.status   || "";
const priority = info.event.extendedProps?.priority || "";
const reminder = info.event.extendedProps?.reminder_minutes;

/*
| Pour les participants, on fusionne internes + externes pour calculer
| le décompte par réponse (accepted / declined / pending).
| On utilise guest_list (nouveaux objets avec .response) si disponible,
| sinon guest_emails en fallback (tous comptés comme "pending").
*/
const internalParticipants = info.event.extendedProps?.internal_participants || [];
const guestList            = info.event.extendedProps?.guest_list            || [];
const guestEmailsFallback  = info.event.extendedProps?.guest_emails          || [];

const allParticipants = [
  ...internalParticipants,
  ...(guestList.length > 0
    ? guestList
    : guestEmailsFallback.map((e) => ({ email: e, response: "pending" }))),
];

const details = [];
if (location) details.push(`Lieu : ${location}`);
if (status)   details.push(`Statut : ${status}`);
if (priority) details.push(`Priorité : ${priority}`);
if (reminder != null) details.push(`Rappel : ${getReminderLabel(reminder)}`);

if (allParticipants.length > 0) {
  // Comptage par statut de réponse pour le tooltip
  const accepted = allParticipants.filter((p) => p.response === "accepted").length;
  const declined = allParticipants.filter((p) => p.response === "declined").length;
  const pending  = allParticipants.filter((p) => !p.response || p.response === "pending").length;

  const parts = [];
  if (accepted > 0) parts.push(`✓ ${accepted}`);
  if (declined > 0) parts.push(`✗ ${declined}`);
  if (pending  > 0) parts.push(`⏳ ${pending}`);

  details.push(`Participants : ${parts.join(" / ")}`);
}

if (details.length > 0) {
info.el.title = details.join(" | ");
}
},

eventDrop: async (info) => {
try {
const ev = info.event;

const payload = {
action: "update",
calendar_id: activeCalendarId,
event_id: parseInt(ev.id, 10),
title: ev.extendedProps?.raw_title || ev.title,
description: ev.extendedProps?.description || "",
location: ev.extendedProps?.location || "",
status: ev.extendedProps?.status || "confirmed",
priority: ev.extendedProps?.priority || "normal",
reminder_minutes: ev.extendedProps?.reminder_minutes ?? null,

// On conserve participants / invités pendant le drag
internal_user_ids: Array.isArray(ev.extendedProps?.internal_user_ids)
? ev.extendedProps.internal_user_ids
: [],
guest_emails: Array.isArray(ev.extendedProps?.guest_emails)
? ev.extendedProps.guest_emails
: [],

all_day: ev.allDay ? 1 : 0,
color: ev.backgroundColor || ev.extendedProps?.color || "#2d9cdb",
client_updated_at: ev.extendedProps?.client_updated_at || null
};

if (ev.allDay) {
payload.start = toAllDayDate(ev.start);
payload.end = getInclusiveAllDayEndFromExclusive(ev.end, ev.start);
} else {
payload.start = ev.start ? ev.start.toISOString() : null;
payload.end = ev.end ? ev.end.toISOString() : null;
}

// On capture le résultat : déplacer un événement change start → champ majeur → mail possible
const result = await apiSaveEvent(payload);

appCalendar.refetchEvents();
// Toast principal : confirmation du déplacement (inchangé)
showToast("Évènement déplacé avec succès.", "success");
// Toast secondaire : résumé des e-mails envoyés suite au changement de date
handleMailResults(result?.mail_results);
} catch (e) {
showToast(e.message, "error", 4200);
info.revert();
}
},

eventResize: async (info) => {
try {
const ev = info.event;

const payload = {
action: "update",
calendar_id: activeCalendarId,
event_id: parseInt(ev.id, 10),
title: ev.extendedProps?.raw_title || ev.title,
description: ev.extendedProps?.description || "",
location: ev.extendedProps?.location || "",
status: ev.extendedProps?.status || "confirmed",
priority: ev.extendedProps?.priority || "normal",
reminder_minutes: ev.extendedProps?.reminder_minutes ?? null,

// On conserve participants / invités pendant le resize
internal_user_ids: Array.isArray(ev.extendedProps?.internal_user_ids)
? ev.extendedProps.internal_user_ids
: [],
guest_emails: Array.isArray(ev.extendedProps?.guest_emails)
? ev.extendedProps.guest_emails
: [],

all_day: ev.allDay ? 1 : 0,
color: ev.backgroundColor || ev.extendedProps?.color || "#2d9cdb",
client_updated_at: ev.extendedProps?.client_updated_at || null
};

if (ev.allDay) {
payload.start = toAllDayDate(ev.start);
payload.end = getInclusiveAllDayEndFromExclusive(ev.end, ev.start);
} else {
payload.start = ev.start ? ev.start.toISOString() : null;
payload.end = ev.end ? ev.end.toISOString() : null;
}

// On capture le résultat : redimensionner change end → champ majeur → mail possible
const result = await apiSaveEvent(payload);

appCalendar.refetchEvents();
// Toast principal : confirmation du redimensionnement (inchangé)
showToast("Durée de l'évènement mise à jour.", "success");
// Toast secondaire : résumé des e-mails envoyés suite au changement de durée
handleMailResults(result?.mail_results);
} catch (e) {
showToast(e.message, "error", 4200);
info.revert();
}
}
});

window.switchCalendar = function (calendarId) {
const nextId = parseInt(calendarId, 10);

if (!nextId || Number.isNaN(nextId)) {
console.error("calendarId invalide pour switchCalendar");
return;
}

activeCalendarId = nextId;
calendarEl.dataset.calendarId = String(nextId);

if (typeof window.setActiveCalendarUI === "function") {
window.setActiveCalendarUI(nextId);
}

appCalendar.refetchEvents();
showToast("Calendrier changé.", "info", 1800);
};

const { modal, eventAllDay } = getModalElements();

document.getElementById("btn-close-modal").addEventListener("click", closeModal);
document.getElementById("btn-cancel-event").addEventListener("click", closeModal);
document.getElementById("btn-save-event").addEventListener("click", saveModalEvent);
document.getElementById("btn-delete-event").addEventListener("click", deleteModalEvent);

eventAllDay.addEventListener("change", updateTimeInputsState);

modal.addEventListener("click", (e) => {
if (e.target === modal) closeModal();
});

document.getElementById("btn-close-confirm-modal").addEventListener("click", closeConfirmModal);
document.getElementById("btn-cancel-confirm").addEventListener("click", closeConfirmModal);
document.getElementById("btn-confirm-action").addEventListener("click", runConfirmAction);

document.getElementById("confirm-modal").addEventListener("click", (e) => {
if (e.target.id === "confirm-modal") {
closeConfirmModal();
}
});

appCalendar.render();

if (typeof window.setActiveCalendarUI === "function") {
window.setActiveCalendarUI(activeCalendarId);
}
});
