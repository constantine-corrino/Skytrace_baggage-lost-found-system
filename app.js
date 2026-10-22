/* ============================================================
   app.js  (backend version)
   Same screens as the prototype, but every read/write now goes
   to the PHP API in /api through fetch(). data.js and matching.js
   are no longer needed - the database and the matching algorithm
   live on the server.

   v2 additions: passenger self-registration, idle session
   time-out (client + server), and a central handler for expired
   sessions.
   ============================================================ */

const session = { role: null, user: null };
let staffFilter = "All";
let pollHandle = null;
let lastSearch = null;         // remembered so the results refresh after a claim
let staffBags = [];
let staffClaims = [];
let knownBagIds = new Set();

/* ---------------- API helper ---------------- */

async function api(path, { method = "GET", body, background = false, skipExpiry = false } = {}) {
  const headers = body ? { "Content-Type": "application/json" } : {};
  // Background polling must NOT count as user activity on the server,
  // otherwise the staff dashboard's 15-second refresh would keep the
  // session alive forever and the idle time-out would never fire.
  if (background) headers["X-Background-Poll"] = "1";

  const res = await fetch("api/" + path, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
    credentials: "same-origin",
  });
  let data = {};
  try { data = await res.json(); } catch (_) { /* non-JSON error page */ }
  if (!res.ok) {
    const err = new Error(data.error || "Something went wrong. Please try again.");
    err.status = res.status;
    // 401 while we think we're signed in = the server ended our session.
    if (res.status === 401 && session.role && !skipExpiry) {
      handleSessionExpired("Your session has expired. Please sign in again.");
    }
    throw err;
  }
  return data;
}

/* ---------------- small DOM helpers ---------------- */

const $ = (id) => document.getElementById(id);

function showView(id) {
  document.querySelectorAll(".view").forEach((v) => v.classList.add("hidden"));
  $(id).classList.remove("hidden");
  window.scrollTo({ top: 0, behavior: "smooth" });
}

function statusClass(status) {
  switch (status) {
    case "Unclaimed": return "status-unclaimed";
    case "Claim Pending": return "status-pending";
    case "Returned": return "status-returned";
    default: return "status-matched";
  }
}

function escapeHtml(str) {
  const div = document.createElement("div");
  div.textContent = str ?? "";
  return div.innerHTML;
}

function showMsg(box, text, kind) {
  box.className = "form-msg " + kind;
  box.textContent = text;
  box.classList.remove("hidden");
}

/* ---------------- navigation / session pill ---------------- */

function goHome() {
  renderHeroBoard();
  showView("view-landing");
}

function renderSessionPill() {
  const pill = $("session-pill");
  if (!session.role) { pill.innerHTML = ""; return; }
  pill.innerHTML = `
    <span class="who">${escapeHtml(session.user.name)}</span>
    <span>·</span>
    <span>${session.role === "staff" ? escapeHtml(session.user.title) : "Passenger"}</span>
    <button id="logout-btn" type="button">Sign out</button>`;
  $("logout-btn").addEventListener("click", logout);
}

function clearLocalSession() {
  session.role = null;
  session.user = null;
  lastSearch = null;
  stopPolling();
  stopIdleWatch();
  renderSessionPill();
}

async function logout() {
  try { await api("auth.php?action=logout", { method: "POST", skipExpiry: true }); } catch (_) {}
  clearLocalSession();
  goHome();
}

/* ---------------- session time-out ----------------
   Two layers:
   1. SERVER (api/security.php) is the real authority - it destroys the
      session after 15 minutes without a user-initiated request.
   2. CLIENT (below) signs the person out on screen after the same idle
      period, and sends a light "keep-alive" request while they are
      genuinely active (mouse/keyboard) so the server does not expire
      them mid-task. */

const IDLE_LIMIT_MS = 15 * 60 * 1000;     // must match IDLE_TIMEOUT in security.php
const KEEPALIVE_EVERY_MS = 2 * 60 * 1000;
const ACTIVITY_EVENTS = ["mousemove", "keydown", "click", "scroll", "touchstart"];
let idleTimer = null;
let lastActivityAt = 0;
let lastKeepAliveAt = 0;

function showNotice(text) {
  const box = $("notice-banner");
  box.textContent = text;
  box.classList.remove("hidden");
  setTimeout(() => box.classList.add("hidden"), 10000);
}

function handleSessionExpired(message) {
  if (!session.role) return;               // already signed out
  clearLocalSession();
  goHome();
  showNotice(message);
}

function onUserActivity() {
  if (!session.role) return;
  const now = Date.now();
  if (now - lastActivityAt < 1000) return; // throttle noisy mousemove events
  lastActivityAt = now;
  resetIdleTimer();

  if (now - lastKeepAliveAt > KEEPALIVE_EVERY_MS) {
    lastKeepAliveAt = now;
    api("auth.php?action=me")
      .then(({ user }) => {
        // 200 with no user = the server already expired us.
        if (!user) handleSessionExpired("Your session has expired. Please sign in again.");
      })
      .catch(() => {});
  }
}

function resetIdleTimer() {
  clearTimeout(idleTimer);
  idleTimer = setTimeout(async () => {
    try { await api("auth.php?action=logout", { method: "POST", skipExpiry: true }); } catch (_) {}
    handleSessionExpired("You were signed out after 15 minutes of inactivity.");
  }, IDLE_LIMIT_MS);
}

function startIdleWatch() {
  stopIdleWatch();
  lastActivityAt = lastKeepAliveAt = Date.now();
  ACTIVITY_EVENTS.forEach((ev) => window.addEventListener(ev, onUserActivity, { passive: true }));
  resetIdleTimer();
}

function stopIdleWatch() {
  clearTimeout(idleTimer);
  idleTimer = null;
  ACTIVITY_EVENTS.forEach((ev) => window.removeEventListener(ev, onUserActivity));
}

/* ---------------- landing hero board ---------------- */

async function renderHeroBoard() {
  try {
    const { bags } = await api("bags.php?board=1");
    $("hero-board-rows").innerHTML = bags.map((bag) => `
      <div class="board-row">
        <span class="mono">${escapeHtml(bag.flightNumber)}</span>
        <span class="mono">${escapeHtml(bag.tagNumber)}</span>
        <span class="mono">${escapeHtml(bag.foundLocation.split(",")[0])}</span>
        <span class="cell-status ${statusClass(bag.status)}">${escapeHtml(bag.status)}</span>
      </div>`).join("");
  } catch (_) {
    $("hero-board-rows").innerHTML = `<div class="empty-state">Live board unavailable.</div>`;
  }
}

/* ============================================================
   PASSENGER PORTAL
   ============================================================ */

async function handlePassengerLogin(e) {
  e.preventDefault();
  const msgBox = $("pax-login-msg");
  try {
    const { user } = await api("auth.php?action=login-passenger", {
      method: "POST",
      body: {
        bookingRef: $("pax-booking-ref").value.trim(),
        lastName: $("pax-last-name").value.trim(),
      },
    });
    msgBox.classList.add("hidden");
    startPassengerSession(user);
  } catch (err) {
    showMsg(msgBox, err.message, "error");
  }
}

function startPassengerSession(user) {
  session.role = "passenger";
  session.user = user;
  renderSessionPill();
  enterPassengerDashboard();
}

/* ---------------- passenger registration ---------------- */

const NAME_RE = /^[\p{L}][\p{L} '\-]{0,44}$/u;
const BOOKING_RE = /^[A-Z0-9]{6}$/;
const FLIGHT_RE = /^[A-Z0-9]{3,10}$/;
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

async function handleRegister(e) {
  e.preventDefault();
  const form = e.target;
  const msgBox = $("pax-register-msg");

  const payload = {
    firstName: $("newpax-first-name").value.trim(),
    lastName: $("newpax-last-name").value.trim(),
    email: $("newpax-email").value.trim(),
    bookingRef: $("newpax-booking-ref").value.trim().toUpperCase(),
    flightNumber: $("newpax-flight").value.replace(/\s+/g, "").toUpperCase(),
  };

  // Client-side checks are only for friendlier errors; the server re-validates everything.
  let problem = null;
  if (!NAME_RE.test(payload.firstName)) problem = "Please enter a valid first name.";
  else if (!NAME_RE.test(payload.lastName)) problem = "Please enter a valid last name.";
  else if (!EMAIL_RE.test(payload.email)) problem = "Please enter a valid email address.";
  else if (!BOOKING_RE.test(payload.bookingRef)) problem = "Booking reference must be 6 letters/numbers.";
  else if (!FLIGHT_RE.test(payload.flightNumber)) problem = "Please enter a valid flight number.";
  if (problem) { showMsg(msgBox, problem, "error"); return; }

  try {
    // The server creates the account AND signs the new passenger in.
    const { user } = await api("auth.php?action=register-passenger", { method: "POST", body: payload });
    msgBox.classList.add("hidden");
    form.reset();
    startPassengerSession(user);
  } catch (err) {
    showMsg(msgBox, err.message, "error");
  }
}

function enterPassengerDashboard() {
  startIdleWatch();
  $("pax-welcome-name").textContent = session.user.name;
  $("pax-flight-input").value = session.user.flightNumber;
  $("pax-search-results").innerHTML = "";
  $("pax-search-hint").classList.remove("hidden");
  renderMyClaims();
  showView("view-passenger-dashboard");
}

async function handleBagSearch(e) {
  if (e && e.preventDefault) e.preventDefault();
  lastSearch = {
    flightNumber: $("pax-flight-input").value,
    tagNumber: $("pax-tag-input").value,
    color: $("pax-color-input").value,
    brand: $("pax-brand-input").value,
    description: $("pax-desc-input").value,
  };
  try {
    const { results } = await api("search.php", { method: "POST", body: lastSearch });
    renderMatches(results);
  } catch (err) {
    $("pax-search-hint").classList.add("hidden");
    $("pax-search-results").innerHTML = `<div class="empty-state">${escapeHtml(err.message)}</div>`;
  }
}

function renderMatches(results) {
  $("pax-search-hint").classList.add("hidden");
  const container = $("pax-search-results");

  if (results.length === 0) {
    container.innerHTML = `<div class="empty-state">No bags matched those details yet. New bags are logged by staff throughout the day — try again later, or narrow down the description.</div>`;
    return;
  }

  container.innerHTML = results.map(({ bag, score, alreadyClaimed }) => {
    const tier = score >= 70 ? "high" : score >= 40 ? "" : "low";
    return `
      <div class="match-card">
        <div class="match-info">
          <h4>${escapeHtml(bag.brand)} ${escapeHtml(bag.type.toLowerCase())} · ${escapeHtml(bag.color)}</h4>
          <p>Flight ${escapeHtml(bag.flightNumber)} (${escapeHtml(bag.route)}) · found ${escapeHtml(bag.dateFound)} at ${escapeHtml(bag.foundLocation)}</p>
          <p>${escapeHtml(bag.description)}</p>
        </div>
        <div style="display:flex; flex-direction:column; align-items:flex-end; gap:10px;">
          <span class="confidence ${tier}">${score}%</span>
          <button class="btn btn-primary btn-small" ${alreadyClaimed ? "disabled" : ""}
            onclick="submitClaim('${bag.id}')">
            ${alreadyClaimed ? "Claim submitted" : "This is my bag"}
          </button>
        </div>
      </div>`;
  }).join("");
}

async function submitClaim(bagId) {
  try {
    await api("claims.php", { method: "POST", body: { bagId, search: lastSearch } });
  } catch (err) {
    alert(err.message);
  }
  renderMyClaims();
  handleBagSearch();          // refresh the cards so the button updates
}

async function renderMyClaims() {
  const list = $("pax-claims-list");
  let claims = [];
  try {
    ({ claims } = await api("claims.php"));
  } catch (err) {
    list.innerHTML = `<div class="empty-state">${escapeHtml(err.message)}</div>`;
    return;
  }

  if (claims.length === 0) {
    list.innerHTML = `<div class="empty-state">No claims filed yet. Search above and select "This is my bag" once you find a match.</div>`;
    return;
  }

  list.innerHTML = claims.map((c) => {
    const pillClass = c.status === "Pending" ? "status-pending" : c.status === "Approved" ? "status-returned" : "status-unclaimed";
    return `<li>
      <span><strong>${escapeHtml(c.id)}</strong> · ${escapeHtml(c.bagLabel)} (${escapeHtml(c.bagId)}) · submitted ${escapeHtml(c.submittedAt)}</span>
      <span class="pill ${pillClass}">${escapeHtml(c.status)}</span>
    </li>`;
  }).join("");
}

/* ============================================================
   STAFF PORTAL
   ============================================================ */

async function handleStaffLogin(e) {
  e.preventDefault();
  const msgBox = $("staff-login-msg");
  try {
    const { user } = await api("auth.php?action=login-staff", {
      method: "POST",
      body: { username: $("staff-username").value.trim(), password: $("staff-password").value },
    });
    msgBox.classList.add("hidden");
    $("staff-password").value = "";
    session.role = "staff";
    session.user = user;
    renderSessionPill();
    enterStaffDashboard();
  } catch (err) {
    showMsg(msgBox, err.message, "error");
  }
}

async function enterStaffDashboard() {
  startIdleWatch();
  staffFilter = "All";
  knownBagIds = new Set();
  await refreshStaff();
  showView("view-staff-dashboard");
  startPolling();
}

/* Pull the latest bags + pending claims from the server and redraw. */
async function refreshStaff(flapRowId = null, background = false) {
  try {
    const [b, c] = await Promise.all([
      api("bags.php", { background }),
      api("claims.php", { background }),
    ]);
    staffBags = b.bags;
    staffClaims = c.claims;
  } catch (err) {
    // A 401 is already handled centrally in api() (session expired).
    return;
  }

  // A bag we have not seen before (e.g. logged by a colleague) gets the "flap" animation.
  if (!flapRowId && knownBagIds.size) {
    const fresh = staffBags.find((bag) => !knownBagIds.has(bag.id));
    if (fresh) flapRowId = fresh.id;
  }
  knownBagIds = new Set(staffBags.map((bag) => bag.id));

  renderStatStrip();
  renderBagTable(flapRowId);
  renderPendingClaims();
}

function renderStatStrip() {
  const count = (s) => staffBags.filter((b) => b.status === s).length;
  $("stat-total").textContent = staffBags.length;
  $("stat-unclaimed").textContent = count("Unclaimed");
  $("stat-pending").textContent = count("Claim Pending");
  $("stat-returned").textContent = count("Returned");
}

function setStaffFilter(status) {
  staffFilter = status;
  document.querySelectorAll(".filter-chip").forEach((chip) => {
    chip.classList.toggle("active", chip.dataset.status === status);
  });
  renderBagTable();
}

function renderBagTable(flapRowId = null) {
  const rows = staffFilter === "All" ? staffBags : staffBags.filter((b) => b.status === staffFilter);
  const tbody = $("staff-bag-tbody");

  if (rows.length === 0) {
    tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state">No bags in this category right now.</div></td></tr>`;
    return;
  }

  // The server already sends newest first.
  tbody.innerHTML = rows.map((bag) => `
    <tr>
      <td class="mono">${escapeHtml(bag.id)}</td>
      <td class="mono">${escapeHtml(bag.tagNumber)}</td>
      <td class="mono">${escapeHtml(bag.flightNumber)}</td>
      <td>${escapeHtml(bag.brand)} ${escapeHtml(bag.type.toLowerCase())}</td>
      <td class="bag-desc-cell">${escapeHtml(bag.foundLocation)}</td>
      <td><span class="cell-status ${statusClass(bag.status)} ${bag.id === flapRowId ? "flap" : ""}">${escapeHtml(bag.status)}</span></td>
      <td>
        ${bag.status === "Unclaimed"
          ? `<button class="btn btn-outline btn-small" onclick="markReturned('${bag.id}')">Mark returned</button>`
          : bag.status === "Returned" ? "—" : "Awaiting claim review"}
      </td>
    </tr>`).join("");
}

async function handleRegisterBag(e) {
  e.preventDefault();
  const form = e.target;
  const confirmBox = $("register-msg");
  try {
    const { bag } = await api("bags.php", {
      method: "POST",
      body: {
        tagNumber: $("reg-tag").value,
        flightNumber: $("reg-flight").value,
        route: $("reg-route").value,
        color: $("reg-color").value,
        brand: $("reg-brand").value,
        type: $("reg-type").value,
        description: $("reg-desc").value,
        dateFound: $("reg-date").value,
        foundLocation: $("reg-location").value,
      },
    });
    form.reset();
    showMsg(confirmBox, `Logged ${bag.id} — it will now surface in passenger searches.`, "success");
    setTimeout(() => confirmBox.classList.add("hidden"), 4000);
    setStaffFilter("All");
    await refreshStaff(bag.id);
  } catch (err) {
    showMsg(confirmBox, err.message, "error");
  }
}

async function markReturned(bagId) {
  try {
    await api("bags.php?id=" + encodeURIComponent(bagId), { method: "PATCH" });
  } catch (err) { alert(err.message); }
  refreshStaff(bagId);
}

function renderPendingClaims() {
  const list = $("staff-claims-list");
  if (staffClaims.length === 0) {
    list.innerHTML = `<div class="empty-state">No claims waiting on review.</div>`;
    return;
  }
  list.innerHTML = staffClaims.map((c) => `
    <li>
      <span>
        <strong>${escapeHtml(c.passengerName)}</strong> claims ${escapeHtml(c.bagId)}
        (${escapeHtml(c.bagLabel)}) · algorithm confidence ${c.confidence}%
      </span>
      <span class="row-actions">
        <button class="btn btn-primary btn-small" onclick="reviewClaim('${c.id}', 'approve')">Approve</button>
        <button class="btn btn-outline btn-small" onclick="reviewClaim('${c.id}', 'decline')">Decline</button>
      </span>
    </li>`).join("");
}

async function reviewClaim(claimId, decision) {
  try {
    await api("claims.php?id=" + encodeURIComponent(claimId), { method: "PATCH", body: { decision } });
  } catch (err) { alert(err.message); }
  refreshStaff();
}

/* ---------------- live updates ----------------
   Replaces the old fake "incoming bag" timer: the staff dashboard
   simply asks the server for fresh data every 15 seconds, so bags
   logged by colleagues and new passenger claims appear on their own. */

function startPolling() {
  stopPolling();
  pollHandle = setInterval(() => { if (session.role === "staff") refreshStaff(null, true); }, 15000);
}

function stopPolling() {
  if (pollHandle) { clearInterval(pollHandle); pollHandle = null; }
}

/* ---------------- boot ---------------- */

document.addEventListener("DOMContentLoaded", async () => {
  renderHeroBoard();

  $("go-passenger").addEventListener("click", () => showView("view-passenger-login"));
  $("go-staff").addEventListener("click", () => showView("view-staff-login"));
  $("brand-btn").addEventListener("click", goHome);

  $("pax-login-form").addEventListener("submit", handlePassengerLogin);
  $("pax-to-staff-link").addEventListener("click", () => showView("view-staff-login"));
  $("pax-to-register-link").addEventListener("click", () => showView("view-passenger-register"));
  $("pax-register-form").addEventListener("submit", handleRegister);
  $("pax-register-to-login-link").addEventListener("click", () => showView("view-passenger-login"));
  $("pax-search-form").addEventListener("submit", handleBagSearch);
  $("pax-logout-link").addEventListener("click", logout);

  $("staff-login-form").addEventListener("submit", handleStaffLogin);
  $("staff-to-pax-link").addEventListener("click", () => showView("view-passenger-login"));
  $("register-bag-form").addEventListener("submit", handleRegisterBag);
  $("staff-logout-link").addEventListener("click", logout);

  document.querySelectorAll(".filter-chip").forEach((chip) => {
    chip.addEventListener("click", () => setStaffFilter(chip.dataset.status));
  });

  // If the PHP session is still alive (page reload), drop the user straight back in.
  try {
    const { user } = await api("auth.php?action=me");
    if (user) {
      session.role = user.role;
      session.user = user;
      renderSessionPill();
      user.role === "staff" ? enterStaffDashboard() : enterPassengerDashboard();
    }
  } catch (_) { /* not signed in */ }
});
