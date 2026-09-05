(function () {
  'use strict';

  var BASE = window.APP_BASE || '/';
  var CSRF = window.APP_CSRF || '';
  var STORAGE_KEY = 'app_now_playing';

  function api(path) {
    return BASE + path;
  }

  function formatDuration(sec) {
    if (sec === null || sec === undefined || isNaN(sec)) return '--:--';
    sec = Math.max(0, Math.floor(sec));
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function postJson(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(data),
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); });
  }

  /* ================================================================== *
   * Sidebar: einklappbar (Desktop) + Drawer (Mobil)
   * ================================================================== */
  var shell = document.getElementById('app-shell');
  if (shell) {
    try {
      if (localStorage.getItem('app_sidebar_collapsed') === '1') {
        shell.classList.add('app-sidebar-is-collapsed');
      }
    } catch (e) {}
    document.documentElement.classList.remove('app-sidebar-preload-collapsed');

    var collapseToggle = document.getElementById('sidebar-collapse-toggle');
    if (collapseToggle) {
      var updateCollapseLabel = function (collapsed) {
        var label = collapsed ? 'Menü ausklappen' : 'Menü einklappen';
        collapseToggle.title = label;
        collapseToggle.setAttribute('aria-label', label);
      };
      updateCollapseLabel(shell.classList.contains('app-sidebar-is-collapsed'));
      collapseToggle.addEventListener('click', function () {
        var collapsed = shell.classList.toggle('app-sidebar-is-collapsed');
        try { localStorage.setItem('app_sidebar_collapsed', collapsed ? '1' : '0'); } catch (e) {}
        updateCollapseLabel(collapsed);
      });
    }
    var mobileToggle = document.getElementById('sidebar-toggle');
    if (mobileToggle) {
      mobileToggle.addEventListener('click', function () {
        shell.classList.toggle('app-sidebar-is-open');
      });
    }
    var backdrop = document.getElementById('sidebar-backdrop');
    if (backdrop) {
      backdrop.addEventListener('click', function () { shell.classList.remove('app-sidebar-is-open'); });
    }
    document.querySelectorAll('.app-sidebar .pnk-nav-item').forEach(function (a) {
      a.addEventListener('click', function () { shell.classList.remove('app-sidebar-is-open'); });
    });
  }

  /* ================================================================== *
   * Now-Playing / Wiedergabe - persistente Player-Leiste, auf jeder
   * eingeloggten Admin-Seite vorhanden (siehe templates/admin_header.php),
   * damit die Musik beim Navigieren zwischen Seiten nie unterbrochen wird.
   * Zwei <audio>-Elemente ermoeglichen Crossfade (siehe weiter unten).
   * ================================================================== */
  var npBar = document.getElementById('nowplaying');
  var audioA = document.getElementById('audio-el');
  var audioB = document.getElementById('audio-el-b');

  if (npBar && audioA && audioB) {
    var activeAudio = audioA;
    var standbyAudio = audioB;
    var currentTrackId = null;
    var autoDjEnabled = false;
    var crossfadeEnabled = false;
    var crossfadeSeconds = 3;
    var crossfading = false;
    var playlistItems = [];
    var isDragging = false;

    var titleEl = document.getElementById('np-title');
    var artistEl = document.getElementById('np-artist');
    var nextEl = document.getElementById('np-next');
    var playBtn = document.getElementById('btn-playpause');
    var prevBtn = document.getElementById('btn-prev');
    var nextBtn = document.getElementById('btn-next');
    var seek = document.getElementById('np-seek');
    var curEl = document.getElementById('np-current');
    var durEl = document.getElementById('np-duration');
    var seeking = false;

    var tickerWrap = document.getElementById('ticker-wrap');
    var tickerText = document.getElementById('ticker-text');
    var countdownWrap = document.getElementById('countdown-wrap');
    var countdownValue = document.getElementById('countdown-value');
    var tickerEnabled = false;
    var countdownEnabled = false;

    function streamUrl(trackId) {
      return api('api/stream.php?id=' + trackId);
    }

    function highlightPlayingRow(id) {
      document.querySelectorAll('.app-track-row.is-playing').forEach(function (el) {
        el.classList.remove('is-playing');
      });
      var row = document.querySelector('.app-track-row[data-id="' + id + '"]');
      if (row) row.classList.add('is-playing');
    }

    function updateTicker() {
      if (!tickerWrap) return;
      if (tickerEnabled && currentTrackId !== null) {
        tickerWrap.hidden = false;
        tickerText.textContent = '🎵 Jetzt läuft: ' + titleEl.textContent + (artistEl.textContent ? ' – ' + artistEl.textContent : '');
      } else {
        tickerWrap.hidden = true;
      }
    }

    function updateCountdown() {
      if (!countdownWrap) return;
      if (!countdownEnabled || currentTrackId === null || !activeAudio.duration || isNaN(activeAudio.duration)) {
        countdownWrap.hidden = true;
        return;
      }
      countdownWrap.hidden = false;
      countdownValue.textContent = formatDuration(activeAudio.duration - activeAudio.currentTime);
    }

    function findCurrentIndex(items) {
      if (currentTrackId === null) return -1;
      for (var i = 0; i < items.length; i++) {
        if (items[i].track_id === currentTrackId) return i;
      }
      return -1;
    }

    function nextItemAfterCurrent(items) {
      var idx = findCurrentIndex(items);
      if (idx === -1) return items.length ? items[0] : null;
      return items[idx + 1] || null;
    }

    function saveNowPlaying() {
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
          trackId: currentTrackId,
          title: titleEl.textContent,
          artist: artistEl.textContent,
          position: activeAudio.currentTime || 0,
          playing: !activeAudio.paused,
        }));
      } catch (e) {}
    }

    function clearNowPlaying() {
      try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
    }

    /** Laedt einen Track in das aktive <audio>-Element und spielt ihn ab (kein Playlist-Seiteneffekt). */
    function loadAndPlay(trackId, title, artist) {
      currentTrackId = trackId;
      activeAudio.src = streamUrl(trackId);
      activeAudio.currentTime = 0;
      activeAudio.volume = 1;
      activeAudio.play().catch(function () {});
      npBar.hidden = false;
      titleEl.textContent = title || '(ohne Titel)';
      artistEl.textContent = artist || '';
      highlightPlayingRow(trackId);
      updateTicker();
      saveNowPlaying();
    }

    /** Admin spielt einen Bibliothek-/Playlist-Track sofort: stellt ihn an den Anfang der Playlist. */
    function playTrack(track) {
      postJson(api('api/playlist.php'), { action: 'play_now', track_id: track.id, csrf_token: CSRF }).then(function () {
        loadAndPlay(track.id, track.title, track.artist);
        setTimeout(refreshPlaylist, 250);
      });
    }
    window.APP_PLAY_TRACK = playTrack; // fuer die Bibliotheks-Liste weiter unten

    function advanceOnServer(finishedTrackId) {
      if (finishedTrackId === null || finishedTrackId === undefined) return;
      postJson(api('api/playlist.php'), { action: 'advance', track_id: finishedTrackId, csrf_token: CSRF });
    }

    /** Kein Crossfade (oder Fallback): naechsten Playlist-Track direkt im aktiven Element weiterspielen. */
    function advanceToNext() {
      var finished = currentTrackId;
      var next = nextItemAfterCurrent(playlistItems);
      advanceOnServer(finished);
      if (next) {
        loadAndPlay(next.track_id, next.title, next.artist);
      } else {
        currentTrackId = null;
        titleEl.textContent = '-';
        artistEl.textContent = '-';
        clearNowPlaying();
      }
      setTimeout(refreshPlaylist, 250);
    }

    /** Ueberblendet weich zum naechsten Playlist-Track statt hart zu schneiden. */
    function beginCrossfade(next) {
      crossfading = true;
      var finished = currentTrackId;
      var fadeMs = Math.max(500, crossfadeSeconds * 1000);
      var startTs = null;

      standbyAudio.src = streamUrl(next.track_id);
      standbyAudio.currentTime = 0;
      standbyAudio.volume = 0;
      standbyAudio.play().catch(function () {});

      function tick(ts) {
        if (!startTs) startTs = ts;
        var t = Math.min(1, (ts - startTs) / fadeMs);
        activeAudio.volume = Math.max(0, 1 - t);
        standbyAudio.volume = Math.min(1, t);
        if (t < 1) {
          requestAnimationFrame(tick);
        } else {
          finishCrossfade(next, finished);
        }
      }
      requestAnimationFrame(tick);
    }

    function finishCrossfade(next, finishedTrackId) {
      activeAudio.pause();
      activeAudio.currentTime = 0;
      var swap = activeAudio;
      activeAudio = standbyAudio;
      standbyAudio = swap;
      activeAudio.volume = 1;
      currentTrackId = next.track_id;
      titleEl.textContent = next.title || '(ohne Titel)';
      artistEl.textContent = next.artist || '';
      highlightPlayingRow(next.track_id);
      updateTicker();
      saveNowPlaying();
      advanceOnServer(finishedTrackId);
      crossfading = false;
      setTimeout(refreshPlaylist, 250);
    }

    function maybeStartCrossfade() {
      if (!crossfadeEnabled || crossfading || !activeAudio.duration || isNaN(activeAudio.duration)) return;
      var remaining = activeAudio.duration - activeAudio.currentTime;
      if (remaining > crossfadeSeconds) return;
      var next = nextItemAfterCurrent(playlistItems);
      if (!next || next.track_id === currentTrackId) return;
      beginCrossfade(next);
    }

    /* -- Steuerelemente: wirken immer auf das gerade aktive <audio>-Element -- */
    if (playBtn) {
      playBtn.addEventListener('click', function () {
        if (activeAudio.paused) { activeAudio.play(); } else { activeAudio.pause(); }
      });
    }
    if (prevBtn) prevBtn.addEventListener('click', function () { activeAudio.currentTime = 0; });
    if (nextBtn) nextBtn.addEventListener('click', function () { if (!crossfading) advanceToNext(); });

    [audioA, audioB].forEach(function (el) {
      el.addEventListener('play', function (e) { if (e.target === activeAudio && playBtn) playBtn.textContent = '⏸'; });
      el.addEventListener('pause', function (e) { if (e.target === activeAudio && playBtn) playBtn.textContent = '▶'; saveNowPlaying(); });
      el.addEventListener('ended', function (e) {
        if (e.target !== activeAudio || crossfading) return;
        advanceToNext();
      });
      el.addEventListener('loadedmetadata', function (e) {
        if (e.target !== activeAudio || !seek || !durEl) return;
        seek.max = activeAudio.duration || 0;
        durEl.textContent = formatDuration(activeAudio.duration);
      });
      el.addEventListener('timeupdate', function (e) {
        if (e.target !== activeAudio) return;
        if (!seeking && seek && curEl) {
          seek.value = activeAudio.currentTime;
          curEl.textContent = formatDuration(activeAudio.currentTime);
        }
        updateCurrentPlaylistProgress();
        updateCountdown();
        maybeStartCrossfade();
        saveNowPlaying();
      });
    });

    if (seek) {
      seek.addEventListener('input', function () { seeking = true; curEl.textContent = formatDuration(seek.value); });
      seek.addEventListener('change', function () { activeAudio.currentTime = parseFloat(seek.value); seeking = false; });
    }

    /* -- Playlist: Anzeige, Fortschritt der laufenden Zeile, "Als naechstes"-Badge, Drag&Drop -- */
    var playlistList = document.getElementById('playlist-list');
    var autoDjToggle = document.getElementById('auto-dj-toggle');
    var autoDjLabelEl = document.getElementById('auto-dj-label');
    var playlistCountEl = document.getElementById('playlist-count');
    var navPlaylistBadge = document.getElementById('nav-playlist-badge');
    var dragSourceId = null;

    function updateCurrentPlaylistProgress() {
      if (!playlistList || currentTrackId === null || !activeAudio.duration) return;
      var row = playlistList.querySelector('.app-playlist-item[data-track-id="' + currentTrackId + '"]');
      if (!row) return;
      var pct = Math.min(100, Math.max(0, (activeAudio.currentTime / activeAudio.duration) * 100));
      row.style.setProperty('--progress', pct + '%');
    }

    function updateNavBadge(count) {
      if (!navPlaylistBadge) return;
      navPlaylistBadge.textContent = count;
      navPlaylistBadge.hidden = count === 0;
    }

    function reorderLocally(sourceId, targetId) {
      var ids = playlistItems.map(function (it) { return String(it.id); });
      var from = ids.indexOf(String(sourceId));
      var to = ids.indexOf(String(targetId));
      if (from === -1 || to === -1) return;
      ids.splice(to, 0, ids.splice(from, 1)[0]);
      postJson(api('api/playlist.php'), { action: 'reorder', ids: ids, csrf_token: CSRF }).then(refreshPlaylist);
    }

    function wireDragAndDrop() {
      playlistList.querySelectorAll('.app-playlist-item').forEach(function (row) {
        row.addEventListener('dragstart', function () {
          dragSourceId = row.getAttribute('data-id');
          isDragging = true;
          row.classList.add('is-dragging');
        });
        row.addEventListener('dragend', function () {
          isDragging = false;
          row.classList.remove('is-dragging');
          playlistList.querySelectorAll('.is-drop-target').forEach(function (r) { r.classList.remove('is-drop-target'); });
        });
        row.addEventListener('dragover', function (e) {
          e.preventDefault();
          e.dataTransfer.dropEffect = 'move';
          row.classList.add('is-drop-target');
        });
        row.addEventListener('dragleave', function () { row.classList.remove('is-drop-target'); });
        row.addEventListener('drop', function (e) {
          e.preventDefault();
          row.classList.remove('is-drop-target');
          var targetId = row.getAttribute('data-id');
          if (!dragSourceId || dragSourceId === targetId) return;
          reorderLocally(dragSourceId, targetId);
        });
      });
    }

    function renderPlaylist(items) {
      playlistItems = items;
      if (playlistCountEl) {
        playlistCountEl.textContent = items.length + ' Song' + (items.length === 1 ? '' : 's') + ' in der Playlist';
      }
      updateNavBadge(items.length);
      var next = nextItemAfterCurrent(items);
      if (nextEl) nextEl.textContent = next ? (next.title || '(ohne Titel)') + (next.artist ? ' – ' + next.artist : '') : '-';

      if (!items.length) {
        playlistList.innerHTML = '<div class="app-empty">Playlist ist leer.</div>';
        return;
      }
      var sourceLabels = { guest: 'Gast-Wunsch', auto: 'Auto', manual: 'Manuell' };
      var html = '';
      items.forEach(function (it) {
        var isCurrent = it.track_id === currentTrackId;
        var isNext = next && next.id === it.id;
        html += '<div class="app-request-item app-playlist-item" draggable="true" data-id="' + it.id + '" data-track-id="' + it.track_id + '">' +
          '<div>' +
            '<div style="font-weight:600;">' + escapeHtml(it.title || '(ohne Titel)') + (isCurrent ? ' <span class="pnk-text-muted">▶ läuft</span>' : '') + '</div>' +
            '<div class="pnk-text-muted" style="font-size:12px;">' + escapeHtml(it.artist || '') +
              ' · <span class="pnk-badge" style="padding:1px 7px;">' + (sourceLabels[it.source] || it.source) + '</span>' +
              (it.guest_name ? ' · ' + escapeHtml(it.guest_name) : '') +
              (isNext ? ' · <span class="app-badge-next">Als Nächstes</span>' : '') + '</div>' +
          '</div>' +
          '<div style="display:flex; gap:6px;">' +
            (isCurrent ? '' : '<button class="pnk-btn pnk-btn--primary pnk-btn--sm btn-pl-play" data-id="' + it.id + '">▶ Jetzt</button>') +
            '<button class="pnk-btn pnk-btn--ghost pnk-btn--sm btn-pl-remove" data-id="' + it.id + '">Entfernen</button>' +
          '</div>' +
        '</div>';
      });
      playlistList.innerHTML = html;

      playlistList.querySelectorAll('.btn-pl-play').forEach(function (btn) {
        var it = items.find(function (x) { return String(x.id) === btn.getAttribute('data-id'); });
        btn.addEventListener('click', function () {
          if (!it) return;
          var oldId = currentTrackId;
          if (oldId !== null && oldId !== it.track_id) advanceOnServer(oldId);
          loadAndPlay(it.track_id, it.title, it.artist);
          setTimeout(refreshPlaylist, 250);
        });
      });
      playlistList.querySelectorAll('.btn-pl-remove').forEach(function (btn) {
        btn.addEventListener('click', function () {
          postJson(api('api/playlist.php'), { action: 'remove', id: btn.getAttribute('data-id'), csrf_token: CSRF })
            .then(refreshPlaylist);
        });
      });
      wireDragAndDrop();
      updateCurrentPlaylistProgress();
    }

    function refreshPlaylist() {
      if (!playlistList || isDragging) return;
      fetch(api('api/playlist.php'))
        .then(function (r) { return r.json(); })
        .then(function (j) {
          autoDjEnabled = !!j.auto_dj;
          crossfadeEnabled = !!j.crossfade_enabled;
          crossfadeSeconds = j.crossfade_seconds || 3;
          if (autoDjToggle) autoDjToggle.checked = autoDjEnabled;
          if (autoDjLabelEl) autoDjLabelEl.textContent = autoDjEnabled ? 'An' : 'Aus';
          renderPlaylist(j.items || []);
        });
    }
    window.APP_REFRESH_PLAYLIST = refreshPlaylist;

    if (playlistList) {
      refreshPlaylist();
      setInterval(refreshPlaylist, 8000);

      if (autoDjToggle) {
        autoDjToggle.addEventListener('change', function () {
          postJson(api('api/playlist.php'), { action: 'set_auto_dj', enabled: autoDjToggle.checked, csrf_token: CSRF })
            .then(function (res) {
              autoDjEnabled = !!(res.body && res.body.auto_dj);
              if (autoDjLabelEl) autoDjLabelEl.textContent = autoDjEnabled ? 'An' : 'Aus';
              refreshPlaylist();
            });
        });
      }
    } else {
      // Auf Seiten ohne Playlist-Widget (z.B. Uebersicht/Bibliothek) trotzdem
      // periodisch Auto-DJ/Crossfade-Settings + Badge/Next-Info abgleichen.
      refreshPlaylistMeta();
      setInterval(refreshPlaylistMeta, 8000);
    }

    function refreshPlaylistMeta() {
      fetch(api('api/playlist.php'))
        .then(function (r) { return r.json(); })
        .then(function (j) {
          crossfadeEnabled = !!j.crossfade_enabled;
          crossfadeSeconds = j.crossfade_seconds || 3;
          playlistItems = j.items || [];
          updateNavBadge(playlistItems.length);
        });
    }

    /* -- Ticker/Countdown: Einstellungen einmalig laden -- */
    function loadDisplaySettings() {
      fetch(api('api/playlist.php'))
        .then(function (r) { return r.json(); })
        .then(function (j) {
          tickerEnabled = !!j.ticker_enabled;
          countdownEnabled = !!j.countdown_enabled;
          updateTicker();
          updateCountdown();
        });
    }
    loadDisplaySettings();

    /* -- Wiedergabe-Zustand ueber Seitenwechsel hinweg fortsetzen -- */
    (function restoreNowPlaying() {
      var raw;
      try { raw = localStorage.getItem(STORAGE_KEY); } catch (e) { return; }
      if (!raw) return;
      var state;
      try { state = JSON.parse(raw); } catch (e) { return; }
      if (!state || state.trackId === null || state.trackId === undefined) return;

      currentTrackId = state.trackId;
      titleEl.textContent = state.title || '-';
      artistEl.textContent = state.artist || '-';
      activeAudio.src = streamUrl(state.trackId);
      activeAudio.volume = 1;
      var resume = function () {
        activeAudio.currentTime = state.position || 0;
        if (state.playing) activeAudio.play().catch(function () {});
        activeAudio.removeEventListener('loadedmetadata', resume);
      };
      activeAudio.addEventListener('loadedmetadata', resume);
      npBar.hidden = false;
      highlightPlayingRow(state.trackId);
    })();
  }

  /* ================================================================== *
   * Bibliotheks-Browser (player.php)
   * ================================================================== */
  var trackList = document.getElementById('track-list');
  if (trackList) {
    var searchInput = document.getElementById('search-input');
    var trackCountEl = document.getElementById('track-count');
    var searchTimer = null;

    function renderTracks(tracks, total) {
      if (!tracks.length) {
        trackList.innerHTML = '<div class="app-empty">Keine Songs gefunden.</div>';
        return;
      }
      var html = '';
      tracks.forEach(function (t) {
        html += '<div class="app-track-row" data-id="' + t.id + '">' +
          '<div class="app-track-row__title">' + escapeHtml(t.title || t.filename || '(ohne Titel)') + '</div>' +
          '<div class="app-track-row__sub">' + escapeHtml(t.artist || '') + (t.album ? ' · ' + escapeHtml(t.album) : '') + '</div>' +
          '<div class="app-track-row__sub">' + formatDuration(t.duration_seconds) + '</div>' +
          '<div class="app-track-row__actions">' +
            '<button class="pnk-btn pnk-btn--ghost pnk-btn--sm btn-add-playlist" type="button" title="Zur Playlist hinzufuegen">+ Playlist</button>' +
            '<button class="pnk-btn pnk-btn--primary pnk-btn--sm btn-play" type="button">▶ Play</button>' +
          '</div>' +
          '</div>';
      });
      trackList.innerHTML = html;
      if (trackCountEl && total !== undefined) {
        trackCountEl.textContent = total + ' Songs insgesamt';
      }
      trackList.querySelectorAll('.app-track-row').forEach(function (row) {
        var id = parseInt(row.getAttribute('data-id'), 10);
        var t = tracks.find(function (x) { return x.id === id; });
        row.querySelector('.btn-play').addEventListener('click', function () { if (window.APP_PLAY_TRACK) window.APP_PLAY_TRACK(t); });
        row.addEventListener('dblclick', function () { if (window.APP_PLAY_TRACK) window.APP_PLAY_TRACK(t); });
        var addBtn = row.querySelector('.btn-add-playlist');
        if (addBtn) {
          addBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            postJson(api('api/playlist.php'), { action: 'add', track_id: id, csrf_token: CSRF }).then(function () {
              if (window.APP_REFRESH_PLAYLIST) window.APP_REFRESH_PLAYLIST();
            });
          });
        }
      });
    }

    function loadTracks(q) {
      fetch(api('api/tracks.php?limit=150&q=' + encodeURIComponent(q || '')))
        .then(function (r) { return r.json(); })
        .then(function (j) { renderTracks(j.tracks || [], j.count); });
    }

    searchInput.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () { loadTracks(searchInput.value); }, 120);
    });
    loadTracks('');
  }

  /* ================================================================== *
   * Wunschliste-Widget auf player.php - Gast-Wuensche werden nie direkt
   * abgespielt, sondern nur angenommen (-> ans Ende der Playlist) oder
   * abgelehnt.
   * ================================================================== */
  var queueList = document.getElementById('queue-list');
  var pendingBadge = document.getElementById('pending-badge');
  if (queueList && document.getElementById('track-list')) {
    function renderQueue(requests) {
      if (!requests.length) {
        queueList.innerHTML = '<div class="app-empty">Keine offenen Wünsche.</div>';
      } else {
        var html = '';
        requests.forEach(function (r) {
          html += '<div class="app-request-item">' +
            '<div>' +
              '<div style="font-weight:600;">' + escapeHtml(r.title) + '</div>' +
              '<div class="pnk-text-muted" style="font-size:12px;">' + escapeHtml(r.artist || '') +
                (r.guest_name ? ' · gewünscht von ' + escapeHtml(r.guest_name) : '') + '</div>' +
            '</div>' +
            '<div style="display:flex; gap:6px;">' +
              '<button class="pnk-btn pnk-btn--primary pnk-btn--sm btn-req-accept" data-req-id="' + r.id + '">✓ Annehmen</button>' +
              '<button class="pnk-btn pnk-btn--ghost pnk-btn--sm btn-req-reject" data-req-id="' + r.id + '">Verwerfen</button>' +
            '</div>' +
          '</div>';
        });
        queueList.innerHTML = html;
        queueList.querySelectorAll('.btn-req-accept').forEach(function (btn) {
          btn.addEventListener('click', function () {
            btn.disabled = true;
            postJson(api('api/requests.php'), { action: 'accept', id: btn.getAttribute('data-req-id'), csrf_token: CSRF })
              .then(function () {
                refreshQueue();
                if (window.APP_REFRESH_PLAYLIST) window.APP_REFRESH_PLAYLIST();
              });
          });
        });
        queueList.querySelectorAll('.btn-req-reject').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var reqId = btn.getAttribute('data-req-id');
            postJson(api('api/requests.php'), { action: 'update_status', id: reqId, status: 'rejected', csrf_token: CSRF })
              .then(refreshQueue);
          });
        });
      }
      if (pendingBadge) pendingBadge.textContent = requests.length + ' offene Wünsche';
    }

    function refreshQueue() {
      fetch(api('api/requests.php?status=pending'))
        .then(function (r) { return r.json(); })
        .then(function (j) { renderQueue(j.requests || []); });
    }
    refreshQueue();
    setInterval(refreshQueue, 8000);
  }

  /* ================================================================== *
   * Scan-Steuerung (admin/library.php)
   * ================================================================== */
  document.querySelectorAll('.btn-scan').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var libraryId = btn.getAttribute('data-library-id');
      var card = btn.closest('.pnk-card[data-library-id]');
      var progressWrap = card.querySelector('.scan-progress');
      var fill = card.querySelector('.app-progressbar__fill');
      var label = card.querySelector('.scan-progress-label');
      btn.disabled = true;
      progressWrap.style.display = 'block';
      label.textContent = 'Starte Scan…';

      postJson(api('api/scan.php'), { action: 'start', library_id: libraryId, csrf_token: CSRF }).then(function (res) {
        if (!res.ok || res.body.error) {
          label.textContent = 'Fehler: ' + (res.body.error || 'unbekannt');
          btn.disabled = false;
          return;
        }
        var total = res.body.total;
        if (total === 0) {
          label.textContent = 'Keine MP3/FLAC-Dateien gefunden.';
          btn.disabled = false;
          return;
        }
        step();

        function step() {
          postJson(api('api/scan.php'), { action: 'step', library_id: libraryId, csrf_token: CSRF }).then(function (res) {
            if (!res.ok || res.body.error) {
              label.textContent = 'Fehler: ' + (res.body.error || 'unbekannt');
              btn.disabled = false;
              return;
            }
            var processed = res.body.processed, tot = res.body.total || total;
            var pct = tot ? Math.round((processed / tot) * 100) : 100;
            fill.style.width = pct + '%';
            label.textContent = processed + ' / ' + tot + ' Dateien verarbeitet…';
            if (res.body.done) {
              label.textContent = 'Fertig: ' + tot + ' Dateien verarbeitet.';
              btn.disabled = false;
              setTimeout(function () { window.location.reload(); }, 1200);
            } else {
              step();
            }
          });
        }
      });
    });
  });

  /* ================================================================== *
   * Ordner-Picker (admin/library.php) - blaettert Server-Verzeichnisse
   * per api/browse_dirs.php durch, damit der absolute Pfad nicht von Hand
   * herausgefunden werden muss.
   * ================================================================== */
  document.querySelectorAll('.btn-browse-dir').forEach(function (btn) {
    var targetInput = document.getElementById(btn.getAttribute('data-target'));
    var backdrop = document.getElementById('dir-picker-backdrop');
    var pathLabel = document.getElementById('dir-picker-path');
    var list = document.getElementById('dir-picker-list');
    var btnUp = document.getElementById('dir-picker-up');
    var btnChoose = document.getElementById('dir-picker-choose');
    var btnCancel = document.getElementById('dir-picker-cancel');
    var currentPath = '/';

    function load(path) {
      fetch(api('api/browse_dirs.php?path=' + encodeURIComponent(path)))
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
          if (!res.ok) {
            list.innerHTML = '<div class="app-empty">' + escapeHtml(res.body.error || 'Fehler beim Laden.') + '</div>';
            return;
          }
          currentPath = res.body.path;
          pathLabel.textContent = currentPath;
          btnUp.disabled = !res.body.parent;
          btnUp.setAttribute('data-parent', res.body.parent || '');
          if (!res.body.dirs.length) {
            list.innerHTML = '<div class="app-empty">Keine Unterordner.</div>';
            return;
          }
          list.innerHTML = res.body.dirs.map(function (name) {
            return '<div class="pnk-list-item app-dir-picker-item" data-name="' + escapeHtml(name) + '">📁 ' + escapeHtml(name) + '</div>';
          }).join('');
          list.querySelectorAll('.app-dir-picker-item').forEach(function (item) {
            item.addEventListener('click', function () {
              var next = (currentPath === '/' ? '' : currentPath) + '/' + item.getAttribute('data-name');
              load(next);
            });
          });
        });
    }

    btn.addEventListener('click', function () {
      backdrop.hidden = false;
      load(targetInput.value.trim() || '/');
    });
    btnUp.addEventListener('click', function () {
      var parent = btnUp.getAttribute('data-parent');
      if (parent) load(parent);
    });
    btnChoose.addEventListener('click', function () {
      targetInput.value = currentPath;
      backdrop.hidden = true;
    });
    btnCancel.addEventListener('click', function () { backdrop.hidden = true; });
    backdrop.addEventListener('click', function (e) { if (e.target === backdrop) backdrop.hidden = true; });
  });

  /* ================================================================== *
   * Player-Sperre (PIN) - rein clientseitiges Blur-Overlay. Die Sperre
   * ruehrt die <audio>-Elemente nicht an, die Musik spielt also ungestoert
   * weiter waehrend die Bedienung gesperrt ist. Der Sidebar-Button ist nur
   * dann ein <button> (sperrt sofort), wenn eine PIN hinterlegt ist -
   * andernfalls ein <a> zur PIN-Einrichtung in den Einstellungen.
   * ================================================================== */
  var lockOverlay = document.getElementById('lock-overlay');
  var lockBtn = document.getElementById('btn-lock');
  if (lockOverlay && lockBtn && lockBtn.tagName === 'BUTTON') {
    var lockDotsWrap = document.getElementById('lock-dots');
    var lockDots = lockDotsWrap.querySelectorAll('span');
    var lockError = document.getElementById('lock-error');
    var lockKeypad = document.getElementById('lock-keypad');
    var pinBuffer = '';

    function updateLockDots() {
      lockDots.forEach(function (dot, i) {
        dot.classList.toggle('is-filled', i < pinBuffer.length);
      });
    }

    function flashLockError(msg) {
      lockError.textContent = msg;
      lockError.style.display = 'block';
      lockDotsWrap.classList.add('is-error');
      setTimeout(function () { lockDotsWrap.classList.remove('is-error'); }, 400);
    }

    function setLockedFlag(locked) {
      try {
        if (locked) sessionStorage.setItem('app_player_locked', '1');
        else sessionStorage.removeItem('app_player_locked');
      } catch (e) {}
    }

    function engageLock() {
      pinBuffer = '';
      updateLockDots();
      lockError.style.display = 'none';
      lockOverlay.hidden = false;
      setLockedFlag(true);
    }

    function disengageLock() {
      lockOverlay.hidden = true;
      setLockedFlag(false);
    }

    function submitPin() {
      postJson(api('api/lock.php'), { action: 'unlock', pin: pinBuffer, csrf_token: CSRF }).then(function (res) {
        if (res.ok && res.body.ok) {
          disengageLock();
          return;
        }
        var msg = (res.body && res.body.error) || 'Falsche PIN.';
        // Falls die PIN inzwischen (z.B. in einem anderen Tab) entfernt wurde,
        // nicht dauerhaft aussperren - Sperre einfach aufheben.
        if (/keine PIN eingerichtet/i.test(msg)) {
          disengageLock();
          return;
        }
        flashLockError(msg);
        pinBuffer = '';
        updateLockDots();
      });
    }

    function addLockDigit(d) {
      if (pinBuffer.length >= 4) return;
      pinBuffer += d;
      updateLockDots();
      if (pinBuffer.length === 4) submitPin();
    }

    lockBtn.addEventListener('click', engageLock);

    lockKeypad.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-key]');
      if (!btn) return;
      var key = btn.getAttribute('data-key');
      if (key === 'clear') { pinBuffer = ''; updateLockDots(); }
      else if (key === 'back') { pinBuffer = pinBuffer.slice(0, -1); updateLockDots(); }
      else { addLockDigit(key); }
    });

    document.addEventListener('keydown', function (e) {
      if (lockOverlay.hidden) return;
      if (e.key >= '0' && e.key <= '9') {
        addLockDigit(e.key);
      } else if (e.key === 'Backspace') {
        pinBuffer = pinBuffer.slice(0, -1);
        updateLockDots();
      }
    });

    // Musik darf nie ueberraschend abreissen: waehrend gesperrt vor dem
    // Verlassen/Neuladen der Seite warnen.
    window.addEventListener('beforeunload', function (e) {
      if (!lockOverlay.hidden) {
        e.preventDefault();
        e.returnValue = '';
      }
    });

    // Nach einem versehentlichen Reload waehrend gesperrt: Overlay sofort wieder zeigen.
    try {
      if (sessionStorage.getItem('app_player_locked') === '1') {
        lockOverlay.hidden = false;
      }
    } catch (e) {}
  }
})();
