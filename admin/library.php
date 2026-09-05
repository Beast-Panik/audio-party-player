<?php

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\Csrf;
use App\Repositories\LibraryRepository;
use App\Util;

Auth::requireLogin();

$repo = new LibraryRepository();
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $path = trim($_POST['path'] ?? '');
        $recursive = isset($_POST['recursive']);
        if ($name === '' || $path === '') {
            $error = 'Name und Pfad sind erforderlich.';
        } elseif (!is_dir($path)) {
            $error = "Verzeichnis nicht gefunden oder nicht lesbar: {$path}";
        } else {
            $repo->create($name, rtrim($path, '/\\'), $recursive);
            $success = 'Bibliothek angelegt. Jetzt einen Scan starten.';
        }
    } elseif ($action === 'delete') {
        $repo->delete((int) $_POST['id']);
        $success = 'Bibliothek entfernt.';
    } elseif ($action === 'update') {
        $id = (int) $_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $path = trim($_POST['path'] ?? '');
        $recursive = isset($_POST['recursive']);
        if ($name !== '' && $path !== '') {
            $repo->update($id, $name, rtrim($path, '/\\'), $recursive);
            $success = 'Bibliothek aktualisiert.';
        }
    }
}

$libraries = $repo->all();

$pageTitle = 'Bibliothek';
$activeNav = 'library';
require __DIR__ . '/../templates/admin_header.php';
?>

<h2 style="margin-top:0;">Bibliothek</h2>
<p class="pnk-text-muted" style="max-width:70ch;">
  Lege hier die Verzeichnisse fest, in denen deine MP3-/FLAC-Dateien liegen
  (einfach per FTP/Datei-Manager dorthin kopieren - ein Upload ueber diese
  Seite ist nicht vorgesehen). Nach dem Anlegen einmal "Scan starten"
  klicken, um Metadaten einzulesen.
</p>

<?php if ($error): ?><div class="pnk-alert pnk-alert--danger" style="margin-bottom:16px;"><?= Util::e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="pnk-alert pnk-alert--success" style="margin-bottom:16px;"><?= Util::e($success) ?></div><?php endif; ?>

<div class="pnk-card" style="margin-bottom:20px;">
  <div class="pnk-card__header"><span class="pnk-card__title">Neue Bibliothek anlegen</span></div>
  <form method="post" action="<?= app_url('admin/library.php') ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="create">
    <div class="grid-2" style="display:grid; grid-template-columns:1fr 2fr; gap:12px;">
      <div>
        <label class="pnk-label">Name</label>
        <input class="pnk-input" type="text" name="name" placeholder="z.B. Partymucke" required>
      </div>
      <div>
        <label class="pnk-label">Absoluter Server-Pfad</label>
        <div style="display:flex; gap:8px;">
          <input class="pnk-input" type="text" name="path" id="library-path-input" placeholder="/home/username/musik" required style="flex:1;">
          <button class="pnk-btn pnk-btn--ghost btn-browse-dir" type="button" data-target="library-path-input" title="Ordner auf dem Server durchsuchen">📁 Ordner wählen</button>
        </div>
      </div>
    </div>
    <div class="pnk-field-row" style="margin:12px 0;">
      <input class="pnk-checkbox" type="checkbox" name="recursive" id="recursive-new" checked>
      <label for="recursive-new">Unterordner einbeziehen</label>
    </div>
    <button class="pnk-btn pnk-btn--primary" type="submit">Anlegen</button>
  </form>
</div>

<?php if (empty($libraries)): ?>
  <div class="pnk-card"><div class="app-empty">Noch keine Bibliothek angelegt.</div></div>
<?php else: ?>
  <?php foreach ($libraries as $lib): ?>
  <div class="pnk-card" style="margin-bottom:16px;" data-library-id="<?= (int) $lib['id'] ?>">
    <div class="pnk-card__header">
      <span class="pnk-card__title"><?= Util::e($lib['name']) ?></span>
      <span class="pnk-badge"><?= (int) $lib['track_count'] ?> Songs</span>
    </div>
    <div style="font-family:var(--pnk-font-mono,monospace); font-size:12px; color:var(--pnk-text-muted); margin-bottom:12px;">
      <?= Util::e($lib['path']) ?> · <?= $lib['recursive'] ? 'inkl. Unterordner' : 'nur oberste Ebene' ?>
      · zuletzt gescannt: <?= Util::e($lib['last_scanned_at'] ?? 'nie') ?>
    </div>

    <div class="scan-progress" style="margin-bottom:12px; display:none;">
      <div class="app-progressbar"><div class="app-progressbar__fill"></div></div>
      <div class="pnk-text-muted scan-progress-label" style="font-size:12px; margin-top:4px;"></div>
    </div>

    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <button class="pnk-btn pnk-btn--primary btn-scan" type="button" data-library-id="<?= (int) $lib['id'] ?>">Scan starten</button>
      <form method="post" action="<?= app_url('admin/library.php') ?>" onsubmit="return confirm('Bibliothek inkl. aller zugehoerigen Songs wirklich entfernen? Dateien auf der Platte bleiben unberuehrt.');" style="display:inline;">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int) $lib['id'] ?>">
        <button class="pnk-btn pnk-btn--danger pnk-btn--sm" type="submit">Entfernen</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="pnk-modal-backdrop" id="dir-picker-backdrop" hidden>
  <div class="pnk-modal" style="width:520px;">
    <div class="pnk-modal__header">
      <span class="pnk-card__title">Ordner wählen</span>
      <button class="pnk-btn pnk-btn--ghost pnk-btn--icon" id="dir-picker-cancel" type="button" aria-label="Schließen">✕</button>
    </div>
    <div class="pnk-text-muted" id="dir-picker-path" style="font-family:var(--pnk-font-mono,monospace); font-size:12px; margin-bottom:8px; word-break:break-all;">/</div>
    <button class="pnk-btn pnk-btn--ghost pnk-btn--sm" id="dir-picker-up" type="button" style="margin-bottom:8px;">⬆ Übergeordneter Ordner</button>
    <div id="dir-picker-list" class="app-dir-picker-list"><div class="app-empty">Lade…</div></div>
    <div class="pnk-modal__footer">
      <button class="pnk-btn" id="dir-picker-cancel-2" type="button" onclick="document.getElementById('dir-picker-backdrop').hidden = true;">Abbrechen</button>
      <button class="pnk-btn pnk-btn--primary" id="dir-picker-choose" type="button">Diesen Ordner übernehmen</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../templates/admin_footer.php'; ?>
