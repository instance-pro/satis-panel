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

// Copy buttons next to snippets: <pre data-snippet>...</pre><button data-copy>
document.querySelectorAll("[data-copy]").forEach((button) => {
  button.addEventListener("click", async () => {
    const pre = button.parentElement?.querySelector("[data-snippet]");
    const value = button.dataset.copyValue ?? pre?.textContent;
    if (!value || !navigator.clipboard) return;
    await navigator.clipboard.writeText(value);
    const label = button.textContent;
    button.textContent = "Copied";
    setTimeout(() => (button.textContent = label), 1500);
  });
});

// Composer auth form: the username field is only used by http-basic and bitbucket-oauth.
document.querySelectorAll("[data-composer-auth-form]").forEach((form) => {
  const type = form.querySelector("[data-auth-type]");
  const username = form.querySelector("[data-auth-username]");
  const update = () => {
    if (!type || !username) return;
    username.hidden = !["http-basic", "bitbucket-oauth"].includes(type.value);
  };
  type?.addEventListener("change", update);
  update();
});

// Composer users: "Change password" prefills the form with the user name.
document.querySelectorAll("[data-change-user]").forEach((button) => {
  button.addEventListener("click", () => {
    const form = document.querySelector("[data-user-form]");
    if (!form) return;
    const username = form.querySelector("[name$='[username]']");
    const password = form.querySelector("[name$='[password]']");
    if (username) username.value = button.dataset.changeUser;
    if (password) password.value = "";
    const title = document.querySelector("[data-user-form-title]");
    if (title) title.textContent = `Change password of ${button.dataset.changeUser}`;
    const submit = document.querySelector("[data-user-form-submit]");
    if (submit) submit.textContent = "Update password";
    form.scrollIntoView({ behavior: "smooth", block: "center" });
    password?.focus();
  });
});

// Composer users: reveal/hide the stored password of one row.
document.querySelectorAll("[data-reveal]").forEach((button) => {
  button.addEventListener("click", () => {
    const row = button.closest("[data-user-row]");
    if (!row) return;
    const show = button.textContent === "Show";
    row.querySelectorAll("[data-secret]").forEach((el) => {
      if (show) {
        el.dataset.masked = el.textContent;
        el.textContent = el.dataset.secret;
      } else {
        el.textContent = el.dataset.masked;
      }
    });
    button.textContent = show ? "Hide" : "Show";
  });
});

// Repository form: fill the webhook secret with a random value.
document.querySelectorAll("[data-generate-secret]").forEach((button) => {
  button.addEventListener("click", () => {
    const input = button.closest("form")?.querySelector("[data-secret-input]");
    if (!input) return;
    const length = Number(button.dataset.secretLength || 48);
    const alphabet = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    const bytes = window.crypto.getRandomValues(new Uint8Array(length));
    input.value = Array.from(bytes, (b) => alphabet[b % alphabet.length]).join("");
    input.dispatchEvent(new Event("change", { bubbles: true }));
  });
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

  const queuePanel = document.querySelector("[data-queue-panel]");
  const renderQueue = (queue) => {
    if (!queuePanel || !queue || !queue.enabled || queue.error) return;
    const badge = queuePanel.querySelector("[data-worker-badge]");
    const meta = queuePanel.querySelector("[data-worker-meta]");
    if (badge) {
      const alive = queue.worker && queue.worker.alive;
      badge.className = alive ? "badge-green" : "badge-red";
      badge.textContent = alive ? `worker ${queue.worker.state}` : "worker not running";
    }
    if (meta) meta.textContent = queue.worker ? `last seen ${queue.worker.seen_at}` : "";
    const list = queuePanel.querySelector("[data-queue-list]");
    if (!list) return;
    list.replaceChildren();
    if (queue.entries.length === 0) {
      const li = document.createElement("li");
      li.className = "py-2 text-fg-muted";
      li.textContent = "Nothing waiting.";
      list.appendChild(li);
      return;
    }
    queue.entries.forEach((entry) => {
      const li = document.createElement("li");
      li.className = "flex flex-wrap items-center gap-3 py-2";
      const badge = document.createElement("span");
      badge.className = "badge-amber";
      badge.textContent = "waiting";
      const repos = document.createElement("span");
      repos.className = "font-mono text-xs";
      repos.textContent = entry.repositories.length ? entry.repositories.join(", ") : "full build";
      const info = document.createElement("span");
      info.className = "text-xs text-fg-muted";
      info.textContent = `queued ${entry.queued_at.replace("T", " ").slice(0, 19)} by ${entry.trigger}`;
      li.append(badge, repos, info);
      list.appendChild(li);
    });
  };

  const queueEnabled = buildPanel.dataset.queueEnabled === "1";
  let wasRunning = buildPanel.dataset.buildRunning === "1";
  const poll = async () => {
    try {
      const response = await fetch(url, { headers: { Accept: "application/json" } });
      if (!response.ok) return;
      const status = await response.json();
      render(status);
      renderQueue(status.queue);
      const waiting = status.queue && status.queue.enabled && status.queue.entries.length > 0;
      if (status.running || waiting) {
        wasRunning = true;
        setTimeout(poll, 2000);
      } else if (wasRunning) {
        wasRunning = false;
        setTimeout(poll, 5000);
      } else if (queueEnabled) {
        setTimeout(poll, 10000);
      }
    } catch (error) {
      setTimeout(poll, 5000);
    }
  };
  if (wasRunning || queueEnabled) poll();
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

// Theme toggle: system -> light -> dark -> system, stored in localStorage.
const THEMES = ["system", "light", "dark"];
const themeIcons = { system: "🖥️", light: "☀️", dark: "🌙" };
const themeLabels = { system: "System", light: "Light", dark: "Dark" };
const mediaDark = window.matchMedia("(prefers-color-scheme: dark)");

function currentTheme() {
  try {
    const stored = localStorage.getItem("theme");
    return THEMES.includes(stored) ? stored : "system";
  } catch (error) {
    return "system";
  }
}

function applyTheme(theme) {
  const dark = theme === "dark" || (theme === "system" && mediaDark.matches);
  document.documentElement.classList.toggle("dark", dark);
  document.querySelectorAll("[data-theme-icon]").forEach((el) => (el.textContent = themeIcons[theme]));
  document.querySelectorAll("[data-theme-label]").forEach((el) => (el.textContent = themeLabels[theme]));
}

document.querySelectorAll("[data-theme-toggle]").forEach((button) => {
  button.addEventListener("click", () => {
    const next = THEMES[(THEMES.indexOf(currentTheme()) + 1) % THEMES.length];
    try {
      localStorage.setItem("theme", next);
    } catch (error) {
      // localStorage unavailable, apply for this page only
    }
    applyTheme(next);
  });
});
mediaDark.addEventListener("change", () => {
  if (currentTheme() === "system") applyTheme("system");
});
applyTheme(currentTheme());
