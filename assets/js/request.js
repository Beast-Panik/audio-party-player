(function () {
  'use strict';

  var BASE = window.APP_BASE || '/';
  var CSRF = window.APP_CSRF || '';
  var PREVIEW_SECONDS = window.APP_PREVIEW_SECONDS || 20;

  function api(path) { return BASE + path; }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function formatDuration(sec) {
    if (sec === null || sec === undefined || isNaN(sec)) return '';
    sec = Math.max(0, Math.floor(sec));
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function postJson(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); });
  }

  function showFeedback(type, message) {
    var el = document.getElementById('feedback');
    el.innerHTML = '<div class="pnk-alert pnk-alert--' + type + '" style="margin-bottom:16px;">' + escapeHtml(message) + '</div>';
    setTimeout(function () { el.innerHTML = ''; }, 5000);
  }

  /* ================================================================== *
   * Ticker: zeigt den aktuell laufenden Track statt eines statischen
   * App-Namens im Kopfbereich.
   * ================================================================== */
  (function initTicker() {
    var wrap = document.getElementById('now-playing-ticker');
    var textEl = document.getElementById('now-playing-text');
    if (!wrap || !textEl) return;

    function load() {
      fetch(api('api/now_playing.php'))
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.title) {
            wrap.hidden = true;
            return;
          }
          wrap.hidden = false;
          textEl.textContent = '🎵 Läuft gerade: ' + j.title + (j.artist ? ' – ' + j.artist : '');
        });
    }
    load();
    setInterval(load, 8000);
  })();

  /* ================================================================== *
   * Namens-Sperre: der zuerst gesetzte Name gilt fuer dieses Geraet
   * dauerhaft, danach schaltet sich die Suche frei. Bis dahin bleiben
   * Suche/Ergebnisse/Begruessung verborgen.
   * ================================================================== */
  var nameInput = document.getElementById('guest-name');
  var confirmNameBtn = document.getElementById('btn-confirm-name');
  var searchCard = document.getElementById('search-card');
  var greetingEl = document.getElementById('guest-greeting');
  var resultsCard = document.getElementById('results-card');
  var lockedIn = false;
  var waitTicker = null;

  function renderGreeting(name, info) {
    if (waitTicker) { clearInterval(waitTicker); waitTicker = null; }
    if (info.remaining === null) {
      greetingEl.textContent = 'Hallo ' + name + ', viel Spaß beim Wünschen!';
      greetingEl.hidden = false;
      return;
    }
    var remaining = info.remaining;
    var waitSeconds = info.wait_seconds || 0;
    function render() {
      if (remaining <= 0) {
        greetingEl.textContent = 'Hallo ' + name + ', du hast aktuell keine Wunschtitel mehr offen'
          + (waitSeconds > 0 ? ' (naechster in ' + formatDuration(waitSeconds) + ')' : '') + '.';
      } else if (waitSeconds > 0) {
        greetingEl.textContent = 'Hallo ' + name + ', du hast für die nächsten ' + formatDuration(waitSeconds)
          + ' noch ' + remaining + ' Wunschtitel offen.';
      } else {
        greetingEl.textContent = 'Hallo ' + name + ', du hast aktuell ' + remaining + ' Wunschtitel offen.';
      }
    }
    render();
    greetingEl.hidden = false;
    if (waitSeconds > 0) {
      waitTicker = setInterval(function () {
        waitSeconds = Math.max(0, waitSeconds - 1);
        render();
        if (waitSeconds <= 0) { clearInterval(waitTicker); waitTicker = null; }
      }, 1000);
    }
  }

  function unlockSearch(name, info) {
    lockedIn = true;
    nameInput.value = name;
    nameInput.readOnly = true;
    if (confirmNameBtn) confirmNameBtn.hidden = true;
    searchCard.hidden = false;
    resultsCard.hidden = false;
    renderGreeting(name, info);
    var searchInput = document.getElementById('search-input');
    if (searchInput) searchInput.focus();
  }

  function confirmName(name) {
    name = name.trim();
    if (name === '') {
      showFeedback('danger', 'Bitte gib deinen Namen ein.');
      return;
    }
    postJson(api('api/requests.php'), { action: 'set_name', name: name, csrf_token: CSRF }).then(function (res) {
      if (!res.ok) {
        showFeedback('danger', (res.body && res.body.error) || 'Name konnte nicht gespeichert werden.');
        return;
      }
      try { localStorage.setItem('app_guest_name', res.body.name); } catch (e) {}
      unlockSearch(res.body.name, res.body);
    });
  }

  if (confirmNameBtn) confirmNameBtn.addEventListener('click', function () { confirmName(nameInput.value); });
  nameInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !lockedIn) { e.preventDefault(); confirmName(nameInput.value); }
  });

  // Bereits bekannter Name (frueherer Besuch, gleiches Geraet) - Sperre
  // direkt beim Laden erneut bestaetigen (idempotent, Server behaelt den
  // zuerst gesetzten Namen).
  try {
    var savedName = localStorage.getItem('app_guest_name');
    if (savedName) confirmName(savedName);
  } catch (e) {}

  /* ================================================================== *
   * Vorhoeren: ein gemeinsames <audio>-Element spielt einen 20s-Ausschnitt
   * aus der Mitte des Tracks (api/preview.php), max. eine Vorschau
   * gleichzeitig.
   * ================================================================== */
  var previewAudio = document.getElementById('preview-audio');
  var activePreviewBtn = null;
  var previewStopTimer = null;

  function stopPreview() {
    if (previewStopTimer) { clearTimeout(previewStopTimer); previewStopTimer = null; }
    previewAudio.pause();
    if (activePreviewBtn) { activePreviewBtn.textContent = '▶'; activePreviewBtn = null; }
  }

  function playPreview(btn, trackId) {
    if (activePreviewBtn === btn) { stopPreview(); return; }
    stopPreview();
    activePreviewBtn = btn;
    btn.textContent = '⏸';
    previewAudio.src = api('api/preview.php?id=' + trackId);
    previewAudio.play().catch(function () {});
    previewStopTimer = setTimeout(stopPreview, PREVIEW_SECONDS * 1000);
  }
  previewAudio.addEventListener('ended', stopPreview);

  /* ================================================================== *
   * Suche / Inspiration
   * ================================================================== */
  var INSPIRATION_LIMIT = 40;
  var searchInputEl = document.getElementById('search-input');
  var resultsList = document.getElementById('results-list');
  var resultsTitle = document.getElementById('results-title');
  var searchTimer = null;

  function renderResults(tracks) {
    stopPreview();
    if (!tracks.length) {
      resultsList.innerHTML = '<div class="app-empty">Nichts gefunden.</div>';
      return;
    }
    var html = '';
    tracks.forEach(function (t) {
      html += '<div class="app-request-item">' +
        (t.has_cover
          ? '<div class="app-guest-result__cover"><img src="' + api('api/cover.php?id=' + t.id) + '" alt="">' +
              '<button class="app-preview-play" type="button" data-id="' + t.id + '" title="20 Sekunden anspielen">▶</button></div>'
          : '') +
        '<div style="flex:1; min-width:0;">' +
          '<div style="font-weight:600;">' + escapeHtml(t.title || '(ohne Titel)') +
            (t.locked ? ' <span class="pnk-badge" title="Kürzlich gespielt">🔒 kürzlich gespielt</span>' : '') + '</div>' +
          '<div class="pnk-text-muted" style="font-size:12px;">' + escapeHtml(t.artist || '') + (t.album ? ' · ' + escapeHtml(t.album) : '') +
            (t.year ? ' · ' + t.year : '') +
            (t.duration_seconds ? ' · ' + formatDuration(t.duration_seconds) : '') + '</div>' +
        '</div>' +
        '<button class="pnk-btn pnk-btn--primary pnk-btn--sm btn-wish" data-id="' + t.id + '"' + (t.locked ? ' disabled' : '') + '>Wünschen</button>' +
      '</div>';
    });
    resultsList.innerHTML = html;
    resultsList.querySelectorAll('.app-preview-play').forEach(function (btn) {
      btn.addEventListener('click', function () { playPreview(btn, btn.getAttribute('data-id')); });
    });
    resultsList.querySelectorAll('.btn-wish').forEach(function (btn) {
      btn.addEventListener('click', function () {
        btn.disabled = true;
        postJson(api('api/requests.php'), { action: 'create', track_id: btn.getAttribute('data-id'), guest_name: nameInput.value, csrf_token: CSRF })
          .then(function (res) {
            if (res.ok) {
              showFeedback('success', 'Wunsch abgeschickt! 🎉');
              btn.textContent = '✓ Gewünscht';
              renderGreeting(nameInput.value, res.body);
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

  if (searchInputEl) {
    searchInputEl.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () { search(searchInputEl.value); }, 120);
    });
  }

  // Immer 40 Songs als Inspiration zeigen, auch ohne Sucheingabe.
  search('');

  /* ================================================================== *
   * Aktuelle Wunschliste - zeigt auch Status (gespielt/abgelehnt) und
   * faellt nach 60 Minuten automatisch aus der Liste (server-seitig
   * gefiltert, siehe api/requests.php status=feed).
   * ================================================================== */
  var STATUS_LABEL = {
    pending: '⏳ offen',
    approved: '✅ kommt',
    played: '🎵 gespielt',
    rejected: '❌ abgelehnt',
  };
  var queueList = document.getElementById('queue-list');
  function renderQueue(requests) {
    if (!requests.length) {
      queueList.innerHTML = '<div class="app-empty">Noch keine Wünsche.</div>';
      return;
    }
    var html = '';
    requests.slice(0, 30).forEach(function (r) {
      html += '<div class="app-request-item">' +
        '<div>' +
          '<div style="font-weight:600;">' + escapeHtml(r.title) + '</div>' +
          '<div class="pnk-text-muted" style="font-size:12px;">' + escapeHtml(r.artist || '') + (r.guest_name ? ' · von ' + escapeHtml(r.guest_name) : '') + '</div>' +
        '</div>' +
        '<span class="pnk-badge">' + (STATUS_LABEL[r.status] || r.status) + '</span>' +
      '</div>';
    });
    queueList.innerHTML = html;
  }
  function loadQueue() {
    fetch(api('api/requests.php?status=feed'))
      .then(function (r) { return r.json(); })
      .then(function (j) { renderQueue(j.requests || []); });
  }
  loadQueue();
  setInterval(loadQueue, 10000);
})();
