(function () {
  'use strict';

  var BASE = window.APP_BASE || '/';
  var CSRF = window.APP_CSRF || '';

  function api(path) { return BASE + path; }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function formatDuration(sec) {
    if (sec === null || sec === undefined || isNaN(sec)) return '';
    sec = Math.floor(sec);
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function showFeedback(type, message) {
    var el = document.getElementById('feedback');
    el.innerHTML = '<div class="pnk-alert pnk-alert--' + type + '" style="margin-bottom:16px;">' + escapeHtml(message) + '</div>';
    setTimeout(function () { el.innerHTML = ''; }, 5000);
  }

  var nameInput = document.getElementById('guest-name');
  try {
    var savedName = localStorage.getItem('app_guest_name');
    if (savedName) nameInput.value = savedName;
  } catch (e) {}
  nameInput.addEventListener('change', function () {
    try { localStorage.setItem('app_guest_name', nameInput.value); } catch (e) {}
  });

  var INSPIRATION_LIMIT = 40;
  var searchInput = document.getElementById('search-input');
  var resultsCard = document.getElementById('results-card');
  var resultsList = document.getElementById('results-list');
  var resultsTitle = document.getElementById('results-title');
  var searchTimer = null;

  function renderResults(tracks) {
    if (!tracks.length) {
      resultsList.innerHTML = '<div class="app-empty">Nichts gefunden.</div>';
      return;
    }
    var html = '';
    tracks.forEach(function (t) {
      html += '<div class="app-request-item">' +
        '<div>' +
          '<div style="font-weight:600;">' + escapeHtml(t.title || '(ohne Titel)') + '</div>' +
          '<div class="pnk-text-muted" style="font-size:12px;">' + escapeHtml(t.artist || '') + (t.album ? ' · ' + escapeHtml(t.album) : '') +
            (t.duration_seconds ? ' · ' + formatDuration(t.duration_seconds) : '') + '</div>' +
        '</div>' +
        '<button class="pnk-btn pnk-btn--primary pnk-btn--sm btn-wish" data-id="' + t.id + '">Wünschen</button>' +
      '</div>';
    });
    resultsList.innerHTML = html;
    resultsList.querySelectorAll('.btn-wish').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (nameInput.value.trim() === '') {
          showFeedback('danger', 'Bitte gib zuerst deinen Namen ein.');
          nameInput.focus();
          return;
        }
        btn.disabled = true;
        fetch(api('api/requests.php'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'create', track_id: btn.getAttribute('data-id'), guest_name: nameInput.value, csrf_token: CSRF }),
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
          .then(function (res) {
            if (res.ok) {
              showFeedback('success', 'Wunsch abgeschickt! 🎉');
              btn.textContent = '✓ Gewünscht';
              loadQueue();
            } else {
              showFeedback('danger', res.body.error || 'Konnte Wunsch nicht abschicken.');
              btn.disabled = false;
            }
          });
      });
    });
  }

  function search(q) {
    q = q || '';
    if (resultsTitle) resultsTitle.textContent = q.trim() === '' ? 'Inspiration' : 'Ergebnisse';
    fetch(api('api/tracks.php?limit=' + INSPIRATION_LIMIT + '&q=' + encodeURIComponent(q)))
      .then(function (r) { return r.json(); })
      .then(function (j) { renderResults(j.tracks || []); });
  }

  searchInput.addEventListener('input', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () { search(searchInput.value); }, 120);
  });

  // Immer 40 Songs als Inspiration zeigen, auch ohne Sucheingabe.
  search('');

  var queueList = document.getElementById('queue-list');
  function renderQueue(requests) {
    if (!requests.length) {
      queueList.innerHTML = '<div class="app-empty">Noch keine Wünsche.</div>';
      return;
    }
    var html = '';
    requests.slice(0, 20).forEach(function (r) {
      html += '<div class="app-request-item">' +
        '<div>' +
          '<div style="font-weight:600;">' + escapeHtml(r.title) + '</div>' +
          '<div class="pnk-text-muted" style="font-size:12px;">' + escapeHtml(r.artist || '') + (r.guest_name ? ' · von ' + escapeHtml(r.guest_name) : '') + '</div>' +
        '</div>' +
      '</div>';
    });
    queueList.innerHTML = html;
  }
  function loadQueue() {
    fetch(api('api/requests.php?status=upcoming'))
      .then(function (r) { return r.json(); })
      .then(function (j) { renderQueue(j.requests || []); });
  }
  loadQueue();
  setInterval(loadQueue, 10000);
})();
