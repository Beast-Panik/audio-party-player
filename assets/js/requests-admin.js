(function () {
  'use strict';

  var BASE = window.APP_BASE || '/';
  var CSRF = window.APP_CSRF || '';
  var listEl = document.getElementById('requests-list');
  var tabs = document.querySelectorAll('#status-tabs .pnk-tab');
  var currentStatus = 'pending';

  function api(path) { return BASE + path; }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function statusBadge(status) {
    var map = { pending: '', approved: 'pnk-badge--accent', played: 'pnk-badge--warm', rejected: 'pnk-badge--danger' };
    var label = { pending: 'Offen', approved: 'Angenommen', played: 'Gespielt', rejected: 'Abgelehnt' };
    return '<span class="pnk-badge ' + (map[status] || '') + '">' + (label[status] || status) + '</span>';
  }

  function postJson(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(data),
    }).then(function (r) { return r.json(); });
  }

  function render(requests) {
    if (!requests.length) {
      listEl.innerHTML = '<div class="app-empty">Keine Eintraege.</div>';
      return;
    }
    var html = '<table class="pnk-table"><thead><tr><th>Song</th><th>Gast</th><th>Status</th><th>Zeit</th><th></th></tr></thead><tbody>';
    requests.forEach(function (r) {
      html += '<tr>' +
        '<td><strong>' + escapeHtml(r.title) + '</strong><br><span class="pnk-text-muted" style="font-size:12px;">' + escapeHtml(r.artist || '') + '</span></td>' +
        '<td>' + escapeHtml(r.guest_name || '-') + '</td>' +
        '<td>' + statusBadge(r.status) + '</td>' +
        '<td style="font-size:12px; color:var(--pnk-text-muted);">' + escapeHtml(r.created_at) + '</td>' +
        '<td style="display:flex; gap:6px; justify-content:flex-end; flex-wrap:wrap;">' +
          (r.status !== 'played' ? '<button class="pnk-btn pnk-btn--sm btn-status" data-id="' + r.id + '" data-status="played">Gespielt</button>' : '') +
          (r.status !== 'rejected' ? '<button class="pnk-btn pnk-btn--ghost pnk-btn--sm btn-status" data-id="' + r.id + '" data-status="rejected">Ablehnen</button>' : '') +
          (r.status !== 'pending' ? '<button class="pnk-btn pnk-btn--ghost pnk-btn--sm btn-status" data-id="' + r.id + '" data-status="pending">Zurueck</button>' : '') +
          '<button class="pnk-btn pnk-btn--danger pnk-btn--sm btn-delete" data-id="' + r.id + '">Löschen</button>' +
        '</td>' +
      '</tr>';
    });
    html += '</tbody></table>';
    listEl.innerHTML = html;

    listEl.querySelectorAll('.btn-status').forEach(function (btn) {
      btn.addEventListener('click', function () {
        postJson(api('api/requests.php'), { action: 'update_status', id: btn.getAttribute('data-id'), status: btn.getAttribute('data-status'), csrf_token: CSRF })
          .then(load);
      });
    });
    listEl.querySelectorAll('.btn-delete').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!confirm('Eintrag wirklich loeschen?')) return;
        postJson(api('api/requests.php'), { action: 'delete', id: btn.getAttribute('data-id'), csrf_token: CSRF })
          .then(load);
      });
    });
  }

  function load() {
    var qs = currentStatus ? '?status=' + encodeURIComponent(currentStatus) : '';
    fetch(api('api/requests.php' + qs))
      .then(function (r) { return r.json(); })
      .then(function (j) { render(j.requests || []); });
  }

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      tabs.forEach(function (t) { t.classList.remove('is-active'); });
      tab.classList.add('is-active');
      currentStatus = tab.getAttribute('data-status');
      load();
    });
  });

  load();
  // Interval fuer Aufraeumen bei Soft-Navigation registrieren (siehe app.js),
  // sonst wuerde bei jedem erneuten Besuch dieser Seite ein weiterer,
  // nie endender Abfrage-Intervall dazukommen.
  (window.APP_PAGE_TIMERS = window.APP_PAGE_TIMERS || []).push(setInterval(load, 10000));
})();
