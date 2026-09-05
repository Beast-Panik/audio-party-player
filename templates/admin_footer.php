  </main>
</div>

<?php if (!empty($showPlayerBar)): ?>
<div class="app-nowplaying" id="nowplaying" hidden>
  <audio id="audio-el" preload="auto"></audio>
  <div class="app-nowplaying__meta">
    <div class="app-nowplaying__title" id="np-title">-</div>
    <div class="app-nowplaying__artist" id="np-artist">-</div>
  </div>
  <div class="app-nowplaying__controls">
    <button class="pnk-btn pnk-btn--icon" id="btn-prev" title="Zurueck (Anfang)">⏮</button>
    <button class="pnk-btn pnk-btn--primary pnk-btn--icon" id="btn-playpause" title="Play/Pause">▶</button>
    <button class="pnk-btn pnk-btn--icon" id="btn-next" title="Naechster in der Warteschlange">⏭</button>
  </div>
  <div class="app-nowplaying__progress">
    <span id="np-current">0:00</span>
    <input type="range" id="np-seek" min="0" max="100" value="0" step="0.1">
    <span id="np-duration">0:00</span>
  </div>
  <div class="app-nowplaying__volume">
    <span>🔊</span>
    <input type="range" id="np-volume" min="0" max="100" value="90">
  </div>
  <button class="pnk-btn pnk-btn--icon" id="btn-lock" title="Player sperren (Musik spielt weiter)">🔒</button>
</div>

<div class="app-lock-overlay" id="lock-overlay" hidden>
  <div class="app-lock-box">
    <div class="app-lock-icon">🔒</div>
    <h2>Player gesperrt</h2>
    <p class="pnk-text-muted">Die Musik läuft weiter. PIN eingeben zum Entsperren.</p>
    <div class="app-lock-dots" id="lock-dots">
      <span></span><span></span><span></span><span></span>
    </div>
    <div class="app-lock-keypad" id="lock-keypad">
      <button type="button" data-key="1">1</button>
      <button type="button" data-key="2">2</button>
      <button type="button" data-key="3">3</button>
      <button type="button" data-key="4">4</button>
      <button type="button" data-key="5">5</button>
      <button type="button" data-key="6">6</button>
      <button type="button" data-key="7">7</button>
      <button type="button" data-key="8">8</button>
      <button type="button" data-key="9">9</button>
      <button type="button" data-key="back">⌫</button>
      <button type="button" data-key="0">0</button>
      <button type="button" data-key="clear">C</button>
    </div>
    <div class="pnk-alert pnk-alert--danger" id="lock-error" style="margin-top:14px; display:none;"></div>
  </div>
</div>
<?php endif; ?>

<script>window.APP_CSRF = <?= json_encode(\App\Csrf::token()) ?>; window.APP_BASE = <?= json_encode(app_url('')) ?>;</script>
<script src="<?= app_url('assets/js/app.js') ?>"></script>
</body>
</html>
