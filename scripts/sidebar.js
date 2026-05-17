/* scripts/sidebar.js */

let calendarsCache = [];

async function apiCalendars(method, payload = null) {
  const options = {
    method,
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin"
  };

  if (payload) {
    options.body = JSON.stringify(payload);
  }

  const res = await fetch("backend/Calendars/calendars.php", options);
  const data = await res.json().catch(() => null);

  if (!res.ok) {
    throw new Error(data?.error || `Erreur calendars (${res.status})`);
  }

  return data;
}

function setActiveCalendarUI(calendarId) {
  const items = document.querySelectorAll(".cal-item");

  items.forEach((item) => {
    const itemId = parseInt(item.dataset.id, 10);
    if (itemId === parseInt(calendarId, 10)) {
      item.classList.add("active");
    } else {
      item.classList.remove("active");
    }
  });
}

window.setActiveCalendarUI = setActiveCalendarUI;

function openCreateCalendarModal() {
  document.getElementById("calendar-modal-title").textContent = "Nouveau calendrier";
  document.getElementById("calendar-id").value = "";
  document.getElementById("calendar-name").value = "";
  document.getElementById("calendar-color").value = "#2d9cdb";
  document.getElementById("btn-delete-calendar").style.display = "none";

  const modal = document.getElementById("calendar-modal");
  modal.classList.remove("hidden");
  modal.setAttribute("aria-hidden", "false");

  document.getElementById("calendar-name").focus();
}

function openEditCalendarModal(calendarData) {
  document.getElementById("calendar-modal-title").textContent = "Modifier le calendrier";
  document.getElementById("calendar-id").value = String(calendarData.id);
  document.getElementById("calendar-name").value = calendarData.title || "";
  document.getElementById("calendar-color").value = calendarData.color || "#2d9cdb";
  document.getElementById("btn-delete-calendar").style.display = "inline-block";

  const modal = document.getElementById("calendar-modal");
  modal.classList.remove("hidden");
  modal.setAttribute("aria-hidden", "false");

  document.getElementById("calendar-name").focus();
}

function closeCalendarModal() {
  const modal = document.getElementById("calendar-modal");
  modal.classList.add("hidden");
  modal.setAttribute("aria-hidden", "true");
}

function renderCalendars(list) {
  calendarsCache = Array.isArray(list) ? list : [];

  const container = document.getElementById("cal-list");
  container.innerHTML = "";

  const currentCalendarId = parseInt(
    document.getElementById("calendar")?.dataset.calendarId || "0",
    10
  );

  calendarsCache.forEach((c) => {
    const item = document.createElement("div");
    item.className = "cal-item";
    item.dataset.id = c.id;

    const left = document.createElement("div");
    left.className = "cal-left";

    const dot = document.createElement("div");
    dot.className = "dot";
    dot.style.background = c.color || "#2d9cdb";

    const title = document.createElement("div");
    title.className = "cal-title";
    title.textContent = c.title;

    left.appendChild(dot);
    left.appendChild(title);

    const right = document.createElement("div");
    right.className = "cal-right";

    const badge = document.createElement("span");
    badge.className = "badge";
    badge.textContent = c.is_owner == 1 ? "Owner" : (c.can_edit == 1 ? "Edit" : "Read");

    const editBtn = document.createElement("button");
    editBtn.type = "button";
    editBtn.className = "mini-icon-btn";
    editBtn.textContent = "✎";
    editBtn.title = "Modifier le calendrier";

    editBtn.addEventListener("click", (e) => {
      e.stopPropagation();

      if (parseInt(c.is_owner, 10) !== 1) {
        window.showToast?.("Seul le propriétaire peut modifier ce calendrier.", "error");
        return;
      }

      openEditCalendarModal(c);
    });

    right.appendChild(badge);

    if (parseInt(c.is_owner, 10) === 1) {
      right.appendChild(editBtn);
    }

    item.appendChild(left);
    item.appendChild(right);

    item.addEventListener("click", () => {
      const selectedId = parseInt(c.id, 10);

      if (window.switchCalendar) {
        window.switchCalendar(selectedId);
      }
    });

    if (parseInt(c.id, 10) === currentCalendarId) {
      item.classList.add("active");
    }

    container.appendChild(item);
  });
}

async function loadCalendars() {
  const data = await apiCalendars("GET");
  renderCalendars(data.calendars || []);
}

async function saveCalendarModal() {
  const id = parseInt(document.getElementById("calendar-id").value || "0", 10);
  const title = document.getElementById("calendar-name").value.trim();
  const color = document.getElementById("calendar-color").value || "#2d9cdb";

  if (title === "") {
    window.showToast?.("Le nom du calendrier est obligatoire.", "error");
    document.getElementById("calendar-name").focus();
    return;
  }

  try {
    const resp = await apiCalendars("POST", {
      action: id > 0 ? "update" : "create",
      calendar_id: id > 0 ? id : undefined,
      title,
      color
    });

    await loadCalendars();

    if (resp?.calendar_id && window.switchCalendar) {
      window.switchCalendar(parseInt(resp.calendar_id, 10));
    } else if (id > 0 && window.switchCalendar) {
      window.switchCalendar(id);
    }

    closeCalendarModal();

    window.showToast?.(
      id > 0 ? "Calendrier mis à jour avec succès." : "Calendrier créé avec succès.",
      "success"
    );
  } catch (e) {
    window.showToast?.(e.message, "error", 4200);
  }
}

async function reallyDeleteCalendar(id) {
  const resp = await apiCalendars("POST", {
    action: "delete",
    calendar_id: id
  });

  await loadCalendars();
  closeCalendarModal();

  if (resp?.next_calendar_id && window.switchCalendar) {
    window.switchCalendar(parseInt(resp.next_calendar_id, 10));
  } else {
    const firstAvailable = calendarsCache[0]?.id || 1;
    if (window.switchCalendar) {
      window.switchCalendar(parseInt(firstAvailable, 10));
    }
  }

  window.showToast?.("Calendrier supprimé.", "success");
}

async function deleteCalendarModal() {
  const id = parseInt(document.getElementById("calendar-id").value || "0", 10);
  if (id <= 0) return;

  window.openConfirmModal?.({
    title: "Supprimer le calendrier",
    message: "Supprimer ce calendrier ? Tous les évènements liés seront aussi supprimés.",
    confirmText: "Supprimer",
    onConfirm: async () => {
      try {
        await reallyDeleteCalendar(id);
      } catch (e) {
        window.showToast?.(e.message, "error", 4200);
      }
    }
  });
}

document.addEventListener("DOMContentLoaded", () => {
  loadCalendars().catch((e) => {
    console.error(e);
    window.showToast?.(e.message, "error", 4200);
  });

  document.getElementById("btn-add-cal").addEventListener("click", openCreateCalendarModal);
  document.getElementById("btn-close-calendar-modal").addEventListener("click", closeCalendarModal);
  document.getElementById("btn-cancel-calendar").addEventListener("click", closeCalendarModal);
  document.getElementById("btn-save-calendar").addEventListener("click", saveCalendarModal);
  document.getElementById("btn-delete-calendar").addEventListener("click", deleteCalendarModal);

  const modal = document.getElementById("calendar-modal");
  modal.addEventListener("click", (e) => {
    if (e.target === modal) {
      closeCalendarModal();
    }
  });
});