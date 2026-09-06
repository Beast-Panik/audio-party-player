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
   * App-Namens im Kopfbereich, plus Herz-Reaktion auf den aktuellen Track.
   * Wird sowohl per Erst-Request als auch fortlaufend per SSE (weiter
   * unten, api/events.php) mit applyNowPlaying() aktualisiert.
   * ================================================================== */
  var tickerWrap = document.getElementById('now-playing-ticker');
  var tickerTextEl = document.getElementById('now-playing-text');
  var reactBtn = document.getElementById('btn-react');
  var reactCountEl = document.getElementById('react-count');
  var nowPlayingTrackId = null;

  function applyNowPlaying(nowPlaying, reactionCount) {
    if (!tickerWrap || !tickerTextEl) return;
    nowPlayingTrackId = (nowPlaying && nowPlaying.track_id) || null;
    if (!nowPlaying || !nowPlaying.title) {
      tickerWrap.hidden = true;
      if (reactBtn) reactBtn.hidden = true;
      return;
    }
    tickerWrap.hidden = false;
    tickerTextEl.textContent = '🎵 Läuft gerade: ' + nowPlaying.title + (nowPlaying.artist ? ' – ' + nowPlaying.artist : '');
    if (reactBtn) {
      reactBtn.hidden = !nowPlayingTrackId;
      reactCountEl.textContent = reactionCount || 0;
    }
  }

  // Schneller erster Render per Einzel-Request, bevor der SSE-Stream weiter
  // unten die erste Nachricht liefert.
  fetch(api('api/now_playing.php')).then(function (r) { return r.json(); }).then(function (j) {
    applyNowPlaying(j, j.reaction_count);
  });

  // Leichtgewichtige Stimmungs-Reaktion auf den aktuell laufenden Track,
  // ohne den vollen Wunsch-Flow (siehe api/reactions.php).
  if (reactBtn) {
    reactBtn.addEventListener('click', function () {
      if (!nowPlayingTrackId) return;
      reactBtn.disabled = true;
      reactBtn.classList.add('is-active');
      postJson(api('api/reactions.php'), { action: 'react', track_id: nowPlayingTrackId, csrf_token: CSRF })
        .then(function (res) {
          reactBtn.disabled = false;
          if (res.ok) reactCountEl.textContent = res.body.count;
          setTimeout(function () { reactBtn.classList.remove('is-active'); }, 400);
        });
    });
  }

  /* ================================================================== *
   * Namens-Sperre: der zuerst gesetzte Name gilt fuer dieses Geraet
   * dauerhaft, danach schaltet sich die Suche frei. Bis dahin bleiben
   * Suche/Ergebnisse/Begruessung verborgen.
   * ================================================================== */
  var nameCard = document.getElementById('name-card');
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
    nameCard.hidden = true;
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
  var jumpBarEl = document.getElementById('jump-bar');
  var searchTimer = null;

  /* A-Z/0-9-Sprungleiste - identisches Verhalten wie initJumpBar() in
   * assets/js/app.js (Bibliothek), hier separat gehalten, da beide Seiten
   * unabhaengige Skripte ohne gemeinsames Modul-System sind. */
  var JUMP_BAR_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'.split('');
  function initJumpBar(container, onPick) {
    if (!container) return;
    var html = '';
    JUMP_BAR_CHARS.forEach(function (ch) {
      html += '<button type="button" class="app-jumpbar__btn" data-ch="' + ch + '">' + ch + '</button>';
    });
    container.innerHTML = html;
    container.querySelectorAll('.app-jumpbar__btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var wasActive = btn.classList.contains('is-active');
        container.querySelectorAll('.app-jumpbar__btn').forEach(function (b) { b.classList.remove('is-active'); });
        if (wasActive) {
          onPick(null);
        } else {
          btn.classList.add('is-active');
          onPick(btn.getAttribute('data-ch'));
        }
      });
    });
  }

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

  function search(q, startsWith) {
    q = q || '';
    if (resultsTitle) resultsTitle.textContent = (q.trim() === '' && !startsWith) ? 'Inspiration' : 'Ergebnisse';
    var url = api('api/tracks.php?limit=' + INSPIRATION_LIMIT + '&q=' + encodeURIComponent(q));
    if (startsWith) url += '&starts_with=' + encodeURIComponent(startsWith);
    fetch(url)
      .then(function (r) { return r.json(); })
      .then(function (j) { renderResults(j.tracks || []); });
  }

  if (searchInputEl) {
    searchInputEl.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        if (jumpBarEl) jumpBarEl.querySelectorAll('.app-jumpbar__btn').forEach(function (b) { b.classList.remove('is-active'); });
        search(searchInputEl.value);
      }, 120);
    });
  }
  initJumpBar(jumpBarEl, function (ch) {
    if (searchInputEl) searchInputEl.value = '';
    search('', ch);
  });

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

  /* ================================================================== *
   * Echtzeit-Updates (Ticker/Herz-Zaehler/Wunschliste) per Server-Sent
   * Events statt 8-10s-Polling - siehe api/events.php. Kurzlebiger Stream
   * (~24s) mit automatischem Reconnect, schonend fuer Shared-Hosting mit
   * strengen PHP-Ausfuehrungszeitlimits.
   * ================================================================== */
  if (window.EventSource) {
    var guestEvents = new EventSource(api('api/events.php?scope=guest'));
    guestEvents.onmessage = function (e) {
      var j;
      try { j = JSON.parse(e.data); } catch (err) { return; }
      applyNowPlaying(j.now_playing || {}, j.reaction_count || 0);
      renderQueue(j.queue || []);
    };
  }
})();
