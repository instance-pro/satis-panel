import "./app.css";

// Confirmation for destructive forms: <form data-confirm="Really delete?">
document.addEventListener("submit", (event) => {
  const form = event.target;
  if (form instanceof HTMLFormElement && form.dataset.confirm) {
    if (!window.confirm(form.dataset.confirm)) {
      event.preventDefault();
    }
  }
});

// Build page: poll the status endpoint while a build is running.
const buildPanel = document.querySelector("[data-build-status-url]");
if (buildPanel) {
  const url = buildPanel.dataset.buildStatusUrl;
  const logEl = buildPanel.querySelector("[data-build-log]");
  const stateEl = buildPanel.querySelector("[data-build-state]");
  const metaEl = buildPanel.querySelector("[data-build-meta]");
  const buttons = document.querySelectorAll("[data-build-button]");

  const render = (status) => {
    if (logEl && typeof status.log === "string") {
      const atBottom = logEl.scrollTop + logEl.clientHeight >= logEl.scrollHeight - 8;
      logEl.textContent = status.log || "(no output yet)";
      if (atBottom) logEl.scrollTop = logEl.scrollHeight;
    }
    if (stateEl) {
      stateEl.textContent = status.label;
      stateEl.className = status.badge_class;
    }
    if (metaEl) metaEl.textContent = status.meta;
    buttons.forEach((button) => {
      button.disabled = status.running;
    });
  };

  let wasRunning = buildPanel.dataset.buildRunning === "1";
  const poll = async () => {
    try {
      const response = await fetch(url, { headers: { Accept: "application/json" } });
      if (!response.ok) return;
      const status = await response.json();
      render(status);
      if (status.running) {
        wasRunning = true;
        setTimeout(poll, 2000);
      } else if (wasRunning) {
        wasRunning = false;
      }
    } catch (error) {
      setTimeout(poll, 5000);
    }
  };
  if (wasRunning) poll();
}

// Stateless CSRF protection (double submit cookie), same logic as Symfony's
// csrf_protection_controller.js recipe: replaces the "csrf-token" placeholder
// with a random token and mirrors it into a cookie before the form is sent.
const nameCheck = /^[-_a-zA-Z0-9]{4,22}$/;
const tokenCheck = /^[-_/+a-zA-Z0-9]{24,}$/;

function generateCsrfToken(form) {
  if (!(form instanceof HTMLFormElement)) return;
  const field = form.querySelector('input[name="_csrf_token"], input[name$="[_token]"], input[name="_token"]');
  if (!field) return;
  let cookieName = field.getAttribute("data-csrf-protection-cookie-value");
  let token = field.value;
  if (!cookieName && nameCheck.test(token)) {
    cookieName = token;
    field.setAttribute("data-csrf-protection-cookie-value", cookieName);
    token = btoa(String.fromCharCode.apply(null, window.crypto.getRandomValues(new Uint8Array(18))));
    field.defaultValue = token;
    field.value = token;
    field.dispatchEvent(new Event("change", { bubbles: true }));
  }
  if (cookieName && tokenCheck.test(token)) {
    const cookie = `${cookieName}_${token}=${cookieName}; path=/; samesite=strict`;
    document.cookie = window.location.protocol === "https:" ? `__Host-${cookie}; secure` : cookie;
  }
}

document.addEventListener("submit", (event) => generateCsrfToken(event.target), true);
