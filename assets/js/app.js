(function () {
  'use strict';

  var BASE = window.APP_BASE || '/';
  var CSRF = window.APP_CSRF || '';

  function api(path) {
    return BASE + path;
  }

  function formatDuration(sec) {
    if (sec === null || sec === undefined || isNaN(sec)) return '--:--';
    sec = Math.floor(sec);
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

  /* ---------------------------------------------------------------- *
   * Now-Playing / Wiedergabe (nur auf Seiten mit #nowplaying vorhanden)
   * ---------------------------------------------------------------- */
  var npBar = document.getElementById('nowplaying');
  var audioEl = document.getElementById('audio-el');
  var currentTrackId = null;
  var autoDjEnabled = false;

  function playTrack(track) {
    if (!audioEl) return;
    currentTrackId = track.id;
    audioEl.src = api('api/stream.php?id=' + track.id);
    audioEl.play().catch(function () {});
    npBar.hidden = false;
    document.getElementById('np-title').textContent = track.title || '(ohne Titel)';
    document.getElementById('np-artist').textContent = track.artist || '';
    highlightPlayingRow(track.id);
    // Track als gespielt markieren (fuer "noch nicht gespielt"-Auswahl beim
    // Auto-DJ-Auffuellen) und aus einer evtl. Playlist-Position entfernen.
    postJson(api('api/playlist.php'), { action: 'mark_played', track_id: track.id, csrf_token: CSRF });
  }

  /** Laedt den ersten Playlist-Eintrag und spielt ihn ab (manueller "Weiter"-Button und Auto-DJ-Fortsetzung). */
  function playNextFromPlaylist() {
    fetch(api('api/playlist.php'))
      .then(function (r) { return r.json(); })
      .then(function (j) {
        autoDjEnabled = !!j.auto_dj;
        var items = j.items || [];
        if (items.length) {
          playTrack({ id: items[0].track_id, title: items[0].title, artist: items[0].artist });
        }
      });
  }

  function highlightPlayingRow(id) {
    document.querySelectorAll('.app-track-row.is-playing').forEach(function (el) {
      el.classList.remove('is-playing');
    });
    var row = document.querySelector('.app-track-row[data-id="' + id + '"]');
    if (row) row.classList.add('is-playing');
  }

  if (audioEl) {
    var playBtn = document.getElementById('btn-playpause');
    var prevBtn = document.getElementById('btn-prev');
    var nextBtn = document.getElementById('btn-next');
    var seek = document.getElementById('np-seek');
    var volume = document.getElementById('np-volume');
    var curEl = document.getElementById('np-current');
    var durEl = document.getElementById('np-duration');
    var seeking = false;

    playBtn.addEventListener('click', function () {
      if (audioEl.paused) { audioEl.play(); } else { audioEl.pause(); }
    });
    audioEl.addEventListener('play', function () { playBtn.textContent = '⏸'; });
    audioEl.addEventListener('pause', function () { playBtn.textContent = '▶'; });
    audioEl.addEventListener('ended', function () { if (autoDjEnabled) playNextFromPlaylist(); });
    prevBtn.addEventListener('click', function () { audioEl.currentTime = 0; });
    if (nextBtn) nextBtn.addEventListener('click', function () { playNextFromPlaylist(); });

    audioEl.addEventListener('loadedmetadata', function () {
      seek.max = audioEl.duration || 0;
      durEl.textContent = formatDuration(audioEl.duration);
    });
    audioEl.addEventListener('timeupdate', function () {
      if (!seeking) {
        seek.value = audioEl.currentTime;
        curEl.textContent = formatDuration(audioEl.currentTime);
      }
    });
    seek.addEventListener('input', function () { seeking = true; curEl.textContent = formatDuration(seek.value); });
    seek.addEventListener('change', function () { audioEl.currentTime = parseFloat(seek.value); seeking = false; });
    volume.addEventListener('input', function () { audioEl.volume = volume.value / 100; });
    audioEl.volume = volume.value / 100;
  }

  /* ---------------------------------------------------------------- *
   * Bibliotheks-Browser (player.php)
   * ---------------------------------------------------------------- */
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
        row.querySelector('.btn-play').addEventListener('click', function () { playTrack(t); });
        row.addEventListener('dblclick', function () { playTrack(t); });
        var addBtn = row.querySelector('.btn-add-playlist');
        if (addBtn) {
          addBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            postJson(api('api/playlist.php'), { action: 'add', track_id: id, csrf_token: CSRF }).then(function () {
              if (typeof refreshPlaylist === 'function') refreshPlaylist();
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

  /* ---------------------------------------------------------------- *
   * Wunschliste-Widget auf player.php
   * ---------------------------------------------------------------- */
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
              '<button class="pnk-btn pnk-btn--primary pnk-btn--sm btn-req-play" data-req-id="' + r.id + '" data-track-id="' + r.track_id + '">▶ Abspielen</button>' +
              '<button class="pnk-btn pnk-btn--ghost pnk-btn--sm btn-req-reject" data-req-id="' + r.id + '">Verwerfen</button>' +
            '</div>' +
          '</div>';
        });
        queueList.innerHTML = html;
        queueList.querySelectorAll('.btn-req-play').forEach(function (btn) {
          var reqId = btn.getAttribute('data-req-id');
          var r = requests.find(function (x) { return String(x.id) === reqId; });
          btn.addEventListener('click', function () {
            if (r) {
              playTrack({ id: r.track_id, title: r.title, artist: r.artist });
            }
            postJson(api('api/requests.php'), { action: 'update_status', id: reqId, status: 'played', csrf_token: CSRF })
              .then(refreshQueue);
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

  /* ---------------------------------------------------------------- *
   * Playlist + Auto-DJ (player.php)
   * ---------------------------------------------------------------- */
  var playlistList = document.getElementById('playlist-list');
  var autoDjToggle = document.getElementById('auto-dj-toggle');
  var autoDjLabelEl = document.getElementById('auto-dj-label');
  var playlistCountEl = document.getElementById('playlist-count');

  function renderPlaylist(items) {
    if (playlistCountEl) {
      playlistCountEl.textContent = items.length + ' Song' + (items.length === 1 ? '' : 's') + ' in der Playlist';
    }
    if (!items.length) {
      playlistList.innerHTML = '<div class="app-empty">Playlist ist leer.</div>';
      return;
    }
    var sourceLabels = { guest: 'Gast-Wunsch', auto: 'Auto', manual: 'Manuell' };
    var html = '';
    items.forEach(function (it, idx) {
      html += '<div class="app-request-item">' +
        '<div>' +
          '<div style="font-weight:600;">' + (idx + 1) + '. ' + escapeHtml(it.title || '(ohne Titel)') + '</div>' +
          '<div class="pnk-text-muted" style="font-size:12px;">' + escapeHtml(it.artist || '') +
            ' · <span class="pnk-badge" style="padding:1px 7px;">' + (sourceLabels[it.source] || it.source) + '</span>' +
            (it.guest_name ? ' · ' + escapeHtml(it.guest_name) : '') + '</div>' +
        '</div>' +
        '<div style="display:flex; gap:6px;">' +
          '<button class="pnk-btn pnk-btn--primary pnk-btn--sm btn-pl-play" data-id="' + it.id + '">▶ Jetzt</button>' +
          '<button class="pnk-btn pnk-btn--ghost pnk-btn--sm btn-pl-remove" data-id="' + it.id + '">Entfernen</button>' +
        '</div>' +
      '</div>';
    });
    playlistList.innerHTML = html;

    playlistList.querySelectorAll('.btn-pl-play').forEach(function (btn) {
      var it = items.find(function (x) { return String(x.id) === btn.getAttribute('data-id'); });
      btn.addEventListener('click', function () {
        if (it) {
          playTrack({ id: it.track_id, title: it.title, artist: it.artist });
        }
        refreshPlaylist();
      });
    });
    playlistList.querySelectorAll('.btn-pl-remove').forEach(function (btn) {
      btn.addEventListener('click', function () {
        postJson(api('api/playlist.php'), { action: 'remove', id: btn.getAttribute('data-id'), csrf_token: CSRF })
          .then(refreshPlaylist);
      });
    });
  }

  function refreshPlaylist() {
    if (!playlistList) return;
    fetch(api('api/playlist.php'))
      .then(function (r) { return r.json(); })
      .then(function (j) {
        autoDjEnabled = !!j.auto_dj;
        if (autoDjToggle) autoDjToggle.checked = autoDjEnabled;
        if (autoDjLabelEl) autoDjLabelEl.textContent = autoDjEnabled ? 'An' : 'Aus';
        renderPlaylist(j.items || []);
      });
  }

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
  }

  /* ---------------------------------------------------------------- *
   * Scan-Steuerung (admin/library.php)
   * ---------------------------------------------------------------- */
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

  /* ---------------------------------------------------------------- *
   * Player-Sperre (PIN) - rein clientseitiges Blur-Overlay. Die Sperre
   * ruehrt das <audio>-Element nicht an, die Musik spielt also ungestoert
   * weiter waehrend die Bedienung gesperrt ist.
   * ---------------------------------------------------------------- */
  var lockOverlay = document.getElementById('lock-overlay');
  var lockBtn = document.getElementById('btn-lock');
  if (lockOverlay && lockBtn) {
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
      fetch(api('api/lock.php?action=status'))
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.configured) {
            alert('Bitte zuerst unter Einstellungen → Player-Sperre eine PIN festlegen.');
            return;
          }
          pinBuffer = '';
          updateLockDots();
          lockError.style.display = 'none';
          lockOverlay.hidden = false;
          setLockedFlag(true);
        });
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
