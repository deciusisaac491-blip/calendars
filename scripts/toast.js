/* ============================================================================
scripts/toast.js
------------------------------------------------------------------------------
Système de notifications visuelles (toasts) — fichier autonome et réutilisable.

Pourquoi ce fichier existe séparément ?
  - index.js est lié au calendrier (FullCalendar, modales, etc.)
  - D'autres pages (ex: users.php) ont besoin des toasts SANS charger index.js
  - Ce fichier contient uniquement showToast(), sans aucune dépendance externe

Utilisation :
  <script src="scripts/toast.js"></script>
  showToast("Message", "success");
  showToast("Erreur", "error");
  showToast("Info", "info", 4000);    // durée personnalisée en ms

Types disponibles :
  "success"  → fond vert,  icône ✓
  "error"    → fond rouge, icône !
  "info"     → fond bleu,  icône i  (valeur par défaut)

Prérequis CSS :
  Le fichier css/calendars.css doit être chargé sur la page.
  Il contient les classes .toast, .toast-container, .toast-success, etc.

Auto-création du container :
  Si #toast-container est absent du HTML, la fonction le crée automatiquement
  dans document.body. Aucune erreur silencieuse, aucun toast perdu.
============================================================================ */

/**
 * Affiche une notification "toast" en bas à droite de l'écran.
 *
 * @param {string} message  - Texte à afficher dans le toast
 * @param {string} type     - "success" | "error" | "info"  (défaut : "info")
 * @param {number} duration - Durée d'affichage en millisecondes (défaut : 3200)
 */
function showToast(message, type = "info", duration = 3200) {

    /* ------------------------------------------------------------------
    | Récupération du container — ou création automatique si absent
    | ------------------------------------------------------------------
    | On cherche d'abord le div #toast-container dans le DOM.
    | S'il n'existe pas (page sans ce div dans le HTML), on le crée
    | dynamiquement et on l'ajoute au body.
    |
    | Cela évite que showToast() échoue silencieusement sur des pages
    | qui n'auraient pas le container dans leur HTML.
    ------------------------------------------------------------------ */
    let container = document.getElementById("toast-container");

    if (!container) {
        // Création du container avec les attributs d'accessibilité
        container = document.createElement("div");
        container.id = "toast-container";
        container.className = "toast-container";

        // aria-live="polite" : les lecteurs d'écran annoncent les nouveaux toasts
        container.setAttribute("aria-live", "polite");
        container.setAttribute("aria-atomic", "true");

        // Ajout au body — sera positionné en fixed par le CSS
        document.body.appendChild(container);
    }

    /* ------------------------------------------------------------------
    | Construction du toast
    | ------------------------------------------------------------------
    | Structure HTML générée :
    |   <div class="toast toast-success">
    |     <div class="toast-icon">✓</div>
    |     <div class="toast-content">Message ici</div>
    |     <button class="toast-close">✕</button>
    |   </div>
    ------------------------------------------------------------------ */

    // Conteneur principal du toast
    const toast = document.createElement("div");
    toast.className = `toast toast-${type}`;

    // Icône à gauche selon le type
    const icon = document.createElement("div");
    icon.className = "toast-icon";
    icon.textContent =
        type === "success" ? "✓" :
        type === "error"   ? "!" :
        "i";

    // Zone de texte centrale
    const content = document.createElement("div");
    content.className = "toast-content";
    content.textContent = message;

    // Bouton de fermeture manuel (croix)
    const closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.className = "toast-close";
    closeBtn.setAttribute("aria-label", "Fermer la notification");
    closeBtn.textContent = "✕";

    /* ------------------------------------------------------------------
    | Logique de suppression
    | ------------------------------------------------------------------
    | Le flag "removed" empêche un double-appel à removeToast() si
    | l'utilisateur clique sur ✕ juste avant l'expiration automatique.
    ------------------------------------------------------------------ */
    let removed = false;

    const removeToast = () => {
        if (removed) return;    // Sécurité anti-double suppression
        removed = true;

        // Classe CSS qui déclenche l'animation de sortie (fade-out)
        toast.classList.add("toast-hide");

        // On attend la fin de l'animation avant de retirer l'élément du DOM
        setTimeout(() => toast.remove(), 220);
    };

    // Fermeture manuelle au clic sur ✕
    closeBtn.addEventListener("click", removeToast);

    // Assemblage des éléments dans le toast
    toast.appendChild(icon);
    toast.appendChild(content);
    toast.appendChild(closeBtn);
    container.appendChild(toast);

    /* ------------------------------------------------------------------
    | Animation d'entrée
    | ------------------------------------------------------------------
    | requestAnimationFrame garantit que le navigateur a bien rendu
    | l'élément dans le DOM avant d'ajouter la classe d'animation.
    | Sans ça, la transition CSS ne se déclencherait pas.
    ------------------------------------------------------------------ */
    requestAnimationFrame(() => {
        toast.classList.add("toast-show");
    });

    // Suppression automatique après la durée définie
    setTimeout(removeToast, duration);
}

/* ----------------------------------------------------------------------------
| Export global
| ----------------------------------------------------------------------------
| On expose showToast sur window pour qu'elle soit accessible depuis n'importe
| quel script chargé sur la même page, sans module ES (import/export).
| Ex : window.showToast?.("msg", "error")  — appel sécurisé depuis sidebar.js
---------------------------------------------------------------------------- */
window.showToast = showToast;
