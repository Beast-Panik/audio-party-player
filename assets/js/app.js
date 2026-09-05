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
   * Sidebar: einklappbar (Desktop) + Drawer (Mobil). Lebt im Kopfbereich
   * und wird von der Soft-Navigation (siehe ganz unten) nie angefasst.
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
   * Now-Playing / Wiedergabe - persistente Player-Leiste, lebt im
   * Kopfbereich (siehe templates/admin_header.php) und wird von der
   * Soft-Navigation nie neu aufgebaut. Genau deshalb spielt die Musik
   * beim Wechsel zwischen Menüpunkten wirklich ohne jede Unterbrechung
   * weiter - es findet gar kein Seitenwechsel mehr statt, der die
   * <audio>-Elemente zerstoeren koennte. Zwei <audio>-Elemente
   * ermoeglichen zusaetzlich Crossfade (siehe weiter unten).
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
    window.APP_HIGHLIGHT_PLAYING = highlightPlayingRow;
    window.APP_GET_CURRENT_TRACK = function () { return currentTrackId; };

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

    /* -- Playlist: Anzeige, Fortschritt der laufenden Zeile, "Als naechstes"-Badge, Drag&Drop --
     * #playlist-list existiert nur auf player.php. Nach einer Soft-Navigation
     * (siehe ganz unten) kann diese Seite jederzeit erscheinen oder
     * verschwinden, daher wird hier bei jedem Tick frisch nachgefragt statt
     * das Element einmalig zu cachen. */
    var dragSourceId = null;

    function updateCurrentPlaylistProgress() {
      var playlistList = document.getElementById('playlist-list');
      if (!playlistList || currentTrackId === null || !activeAudio.duration) return;
      var row = playlistList.querySelector('.app-playlist-item[data-track-id="' + currentTrackId + '"]');
      if (!row) return;
      var pct = Math.min(100, Math.max(0, (activeAudio.currentTime / activeAudio.duration) * 100));
      row.style.setProperty('--progress', pct + '%');
    }

    function updateNavBadge(count) {
      var navPlaylistBadge = document.getElementById('nav-playlist-badge');
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

    function wireDragAndDrop(playlistList) {
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

    function renderPlaylist(playlistList, items) {
      playlistItems = items;
      var playlistCountEl = document.getElementById('playlist-count');
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
      wireDragAndDrop(playlistList);
      updateCurrentPlaylistProgress();
    }

    /** Fragt Playlist/Auto-DJ/Crossfade-Status ab. Laeuft dauerhaft im Hintergrund
     * (nicht nur auf player.php), damit Menue-Badge und "Als naechstes"-Anzeige
     * ueberall aktuell bleiben - unabhaengig davon, ob gerade eine Soft- oder
     * Hart-Navigation stattgefunden hat. #playlist-list wird bei jedem Tick frisch
     * abgefragt und nur gerendert, wenn die Seite es gerade zeigt. */
    function refreshPlaylist() {
      fetch(api('api/playlist.php'))
        .then(function (r) { return r.json(); })
        .then(function (j) {
          autoDjEnabled = !!j.auto_dj;
          crossfadeEnabled = !!j.crossfade_enabled;
          crossfadeSeconds = j.crossfade_seconds || 3;
          playlistItems = j.items || [];
          updateNavBadge(playlistItems.length);
          var next = nextItemAfterCurrent(playlistItems);
          if (nextEl) nextEl.textContent = next ? (next.title || '(ohne Titel)') + (next.artist ? ' – ' + next.artist : '') : '-';

          var autoDjToggle = document.getElementById('auto-dj-toggle');
          var autoDjLabelEl = document.getElementById('auto-dj-label');
          if (autoDjToggle) autoDjToggle.checked = autoDjEnabled;
          if (autoDjLabelEl) autoDjLabelEl.textContent = autoDjEnabled ? 'An' : 'Aus';

          var playlistList = document.getElementById('playlist-list');
          if (playlistList && !isDragging) renderPlaylist(playlistList, playlistItems);
        });
    }
    window.APP_REFRESH_PLAYLIST = refreshPlaylist;
    refreshPlaylist();
    setInterval(refreshPlaylist, 8000);

    /** Auto-DJ-Umschalter auf player.php - Element existiert nur dort und wird bei
     * jeder Soft-Navigation neu erzeugt, daher Listener bei jedem Seiteneintritt
     * frisch anhaengen (siehe initPageWidgets). */
    function initAutoDjToggle() {
      var toggle = document.getElementById('auto-dj-toggle');
      if (!toggle) return;
      var label = document.getElementById('auto-dj-label');
      toggle.addEventListener('change', function () {
        postJson(api('api/playlist.php'), { action: 'set_auto_dj', enabled: toggle.checked, csrf_token: CSRF })
          .then(function (res) {
            autoDjEnabled = !!(res.body && res.body.auto_dj);
            if (label) label.textContent = autoDjEnabled ? 'An' : 'Aus';
            refreshPlaylist();
          });
      });
    }
    window.APP_INIT_AUTO_DJ_TOGGLE = initAutoDjToggle;

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

    /* -- Wiedergabe-Zustand nach einem echten Seitenneuaufbau (harter Reload/
     * erster Aufruf) fortsetzen. Bei einer Soft-Navigation (siehe ganz unten)
     * ist das nie noetig, da die <audio>-Elemente dort gar nicht neu entstehen. -- */
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
        if (state.playing) {
          // Nach einem Seitenwechsel hat das neu geladene Dokument noch keine
          // Nutzerinteraktion - Firefox/Chrome blockieren dann hoerbares
          // Autoplay. Stumm geschaltetes Autoplay ist dagegen immer erlaubt,
          // daher stumm starten und sofort nach Start wieder aufdrehen.
          activeAudio.muted = true;
          var unmute = function () { activeAudio.muted = false; };
          var p = activeAudio.play();
          if (p && typeof p.then === 'function') {
            p.then(unmute).catch(unmute);
          } else {
            unmute();
          }
        }
        activeAudio.removeEventListener('loadedmetadata', resume);
      };
      activeAudio.addEventListener('loadedmetadata', resume);
      npBar.hidden = false;
      highlightPlayingRow(state.trackId);
    })();
  }

  /* ================================================================== *
   * Bibliotheks-Browser (player.php) - #track-list existiert nur dort.
   * In eine Funktion gefasst, damit sie nach jeder Soft-Navigation auf
   * player.php erneut aufgerufen werden kann (siehe initPageWidgets).
   * ================================================================== */
  function initTrackList() {
    var trackList = document.getElementById('track-list');
    if (!trackList) return;
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
      if (window.APP_GET_CURRENT_TRACK && window.APP_HIGHLIGHT_PLAYING) {
        var current = window.APP_GET_CURRENT_TRACK();
        if (current !== null) window.APP_HIGHLIGHT_PLAYING(current);
      }
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
   * abgelehnt. #queue-list existiert nur auf player.php, wird aber wie
   * die Playlist dauerhaft im Hintergrund abgefragt (siehe refreshQueue),
   * damit sie nach einer Soft-Navigation sofort wieder aktuell ist.
   * ================================================================== */
  function renderQueue(requests) {
    var queueList = document.getElementById('queue-list');
    if (!queueList) return;
    var pendingBadge = document.getElementById('pending-badge');
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
    if (!document.getElementById('queue-list')) return;
    fetch(api('api/requests.php?status=pending'))
      .then(function (r) { return r.json(); })
      .then(function (j) { renderQueue(j.requests || []); });
  }
  window.APP_REFRESH_QUEUE = refreshQueue;
  refreshQueue();
  setInterval(refreshQueue, 8000);

  /* ================================================================== *
   * Scan-Steuerung (admin/library.php) - Elemente existieren nur dort und
   * werden bei jeder Soft-Navigation neu erzeugt, daher erneut aufrufbar.
   * ================================================================== */
  function initScanButtons() {
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
                if (window.APP_SOFT_RELOAD) window.APP_SOFT_RELOAD();
              } else {
                step();
              }
            });
          }
        });
      });
    });
  }

  /* ================================================================== *
   * Ordner-Picker (admin/library.php) - blaettert Server-Verzeichnisse
   * per api/browse_dirs.php durch, damit der absolute Pfad nicht von Hand
   * herausgefunden werden muss. Elemente existieren nur dort und werden
   * bei jeder Soft-Navigation neu erzeugt, daher erneut aufrufbar.
   * ================================================================== */
  function initFolderPicker() {
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
  }

  /* ================================================================== *
   * Sammelfunktion: alles, was seitenspezifisch ist (Elemente, die nur
   * auf einer bestimmten Unterseite existieren), wird hier einmal beim
   * echten Seitenaufruf UND nach jeder Soft-Navigation neu verdrahtet.
   * ================================================================== */
  function initPageWidgets() {
    initTrackList();
    initScanButtons();
    initFolderPicker();
    if (window.APP_INIT_AUTO_DJ_TOGGLE) window.APP_INIT_AUTO_DJ_TOGGLE();
    if (window.APP_REFRESH_PLAYLIST) window.APP_REFRESH_PLAYLIST();
    if (window.APP_REFRESH_QUEUE) window.APP_REFRESH_QUEUE();
  }
  window.APP_INIT_PAGE = initPageWidgets;
  initPageWidgets();

  /* ================================================================== *
   * Player-Sperre (PIN) - rein clientseitiges Blur-Overlay. Die Sperre
   * ruehrt die <audio>-Elemente nicht an, die Musik spielt also ungestoert
   * weiter waehrend die Bedienung gesperrt ist. Der Sidebar-Button ist nur
   * dann ein <button> (sperrt sofort), wenn eine PIN hinterlegt ist -
   * andernfalls ein <a> zur PIN-Einrichtung in den Einstellungen. Lebt im
   * Kopfbereich und wird von der Soft-Navigation nie angefasst.
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

  /* ================================================================== *
   * Soft-Navigation: faengt Klicks auf interne Seitenverweise ab und laedt
   * nur den Inhaltsbereich (#app-main) per fetch nach, statt die ganze
   * Seite neu zu laden. Der Kopfbereich mit der Player-Leiste und den
   * beiden <audio>-Elementen bleibt dabei unangetastet im DOM stehen -
   * die Musik spielt beim Wechsel zwischen Menuepunkten dadurch wirklich
   * ohne jede Unterbrechung weiter (kein Seitenwechsel = kein Grund fuer
   * den Browser, die Wiedergabe zu stoppen).
   *
   * Formulare (Bibliothek anlegen, Einstellungen speichern, ...) bleiben
   * bewusst normale, volle Seitenaufrufe - das sind seltene, bewusste
   * Aktionen, keine staendigen Menue-Klicks waehrend der Party.
   * ================================================================== */
  (function initSoftNav() {
    var main = document.getElementById('app-main');
    if (!main) return; // Gast-Seiten haben kein #app-main - dort bleibt alles wie gehabt.

    var PJAX_PATH_RE = /\/(admin\/(index|requests|library|users|settings)\.php|player\.php)$/;

    function isSoftNavUrl(url) {
      return url.origin === window.location.origin && PJAX_PATH_RE.test(url.pathname);
    }

    function runPageScripts(root) {
      root.querySelectorAll('script').forEach(function (old) {
        var fresh = document.createElement('script');
        for (var i = 0; i < old.attributes.length; i++) {
          var attr = old.attributes[i];
          fresh.setAttribute(attr.name, attr.value);
        }
        fresh.textContent = old.textContent;
        old.parentNode.replaceChild(fresh, old);
      });
    }

    function setActiveNav(pathname) {
      document.querySelectorAll('.app-sidebar .pnk-nav-item').forEach(function (a) {
        var href = a.getAttribute('href');
        a.classList.toggle('is-active', !!href && href === pathname);
      });
    }

    function stopPageTimers() {
      (window.APP_PAGE_TIMERS || []).forEach(function (id) { clearInterval(id); });
      window.APP_PAGE_TIMERS = [];
    }

    var loading = false;

    function loadUrl(url, push) {
      if (loading) return;
      loading = true;
      main.classList.add('app-main-loading');
      fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'soft-nav' } })
        .then(function (r) {
          if (!r.ok) throw new Error('http-' + r.status);
          return r.text();
        })
        .then(function (html) {
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var newMain = doc.getElementById('app-main');
          if (!newMain) throw new Error('kein #app-main in der Antwort');
          stopPageTimers();
          document.title = doc.title;
          main.innerHTML = newMain.innerHTML;
          runPageScripts(main);
          setActiveNav(new URL(url, window.location.origin).pathname);
          if (push) history.pushState({ softNav: true }, '', url);
          if (window.APP_INIT_PAGE) window.APP_INIT_PAGE();
          window.scrollTo(0, 0);
        })
        .catch(function () {
          window.location.href = url; // Fallback: ganz normal navigieren
        })
        .then(function () {
          loading = false;
          main.classList.remove('app-main-loading');
        });
    }
    window.APP_SOFT_RELOAD = function () { loadUrl(window.location.href, false); };

    document.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var a = e.target.closest('a');
      if (!a || !a.getAttribute('href')) return;
      if (a.target || a.hasAttribute('download')) return;
      var url;
      try { url = new URL(a.href, window.location.href); } catch (err) { return; }
      if (!isSoftNavUrl(url)) return;
      if (url.pathname === window.location.pathname) return;
      e.preventDefault();
      loadUrl(url.href, true);
    });

    window.addEventListener('popstate', function () {
      loadUrl(window.location.href, false);
    });
  })();
})();
