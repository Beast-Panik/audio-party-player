<?php

namespace App\Repositories;

use App\Database;
use App\Util;

/**
 * Gespeicherte Playlists ("Sets") - eigenstaendig von der Live-Playlist
 * (siehe PlaylistRepository, das ist die aktuelle Abspiel-Warteschlange):
 * eine benannte, wiederverwendbare Zusammenstellung von Tracks, verwaltet
 * unter admin/playlists.php und per api/saved_playlists.php per Klick
 * komplett an die Live-Playlist anhaengbar (siehe
 * PlaylistRepository::add()-Aufrufe in api/saved_playlists.php Action
 * "load_into_player").
 */
final class SavedPlaylistRepository
{
    public function all(): array
    {
        return Database::get()->query(
            'SELECT sp.*, (SELECT COUNT(*) FROM saved_playlist_tracks spt WHERE spt.saved_playlist_id = sp.id) AS track_count
             FROM saved_playlists sp ORDER BY sp.name'
        )->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM saved_playlists WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $name): int
    {
        $now = Util::now();
        $stmt = Database::get()->prepare('INSERT INTO saved_playlists (name, created_at, updated_at) VALUES (?, ?, ?)');
        $stmt->execute([$name, $now, $now]);
        return (int) Database::get()->lastInsertId();
    }

    public function rename(int $id, string $name): void
    {
        $stmt = Database::get()->prepare('UPDATE saved_playlists SET name = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$name, Util::now(), $id]);
    }

    /** Loescht die gespeicherte Playlist samt ihrer Track-Zuordnungen (kein FK/Cascade im Schema - siehe Database::schemaStatements()). */
    public function delete(int $id): void
    {
        $pdo = Database::get();
        $pdo->prepare('DELETE FROM saved_playlist_tracks WHERE saved_playlist_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM saved_playlists WHERE id = ?')->execute([$id]);
    }

    /** Tracks einer gespeicherten Playlist in gespeicherter Reihenfolge, inkl. Track-Infos
     *  (INNER JOIN - zwischenzeitlich geloeschte Tracks fallen dabei automatisch raus). */
    public function tracks(int $playlistId): array
    {
        $stmt = Database::get()->prepare(
            'SELECT spt.id, spt.track_id, spt.position, t.title, t.artist, t.album, t.duration_seconds, t.codec
             FROM saved_playlist_tracks spt
             JOIN tracks t ON t.id = spt.track_id
             WHERE spt.saved_playlist_id = ?
             ORDER BY spt.position ASC, spt.id ASC'
        );
        $stmt->execute([$playlistId]);
        return $stmt->fetchAll();
    }

    /** Haengt einen Track ans Ende einer gespeicherten Playlist an. Anders als
     *  bei der Live-Playlist ist derselbe Track hier bewusst mehrfach erlaubt
     *  (ein Set kann einen Track z.B. bewusst zweimal enthalten). */
    public function addTrack(int $playlistId, int $trackId): int
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 AS p FROM saved_playlist_tracks WHERE saved_playlist_id = ?');
        $stmt->execute([$playlistId]);
        $nextPos = (int) $stmt->fetch()['p'];

        $ins = $pdo->prepare(
            'INSERT INTO saved_playlist_tracks (saved_playlist_id, track_id, position, created_at) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$playlistId, $trackId, $nextPos, Util::now()]);
        $pdo->prepare('UPDATE saved_playlists SET updated_at = ? WHERE id = ?')->execute([Util::now(), $playlistId]);
        return (int) $pdo->lastInsertId();
    }

    /** Entfernt einen einzelnen Eintrag (per saved_playlist_tracks.id, nicht track_id - erlaubt gezieltes Entfernen bei Dubletten). */
    public function removeTrack(int $entryId): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT saved_playlist_id FROM saved_playlist_tracks WHERE id = ?');
        $stmt->execute([$entryId]);
        $row = $stmt->fetch();
        $pdo->prepare('DELETE FROM saved_playlist_tracks WHERE id = ?')->execute([$entryId]);
        if ($row) {
            $pdo->prepare('UPDATE saved_playlists SET updated_at = ? WHERE id = ?')->execute([Util::now(), $row['saved_playlist_id']]);
        }
    }

    /** Schreibt eine neue Reihenfolge fest (Drag&Drop in admin/playlists.php) - $orderedEntryIds sind saved_playlist_tracks-IDs. */
    public function reorder(int $playlistId, array $orderedEntryIds): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE saved_playlist_tracks SET position = ? WHERE id = ? AND saved_playlist_id = ?');
        foreach (array_values($orderedEntryIds) as $i => $entryId) {
            $stmt->execute([$i, (int) $entryId, $playlistId]);
        }
        $pdo->prepare('UPDATE saved_playlists SET updated_at = ? WHERE id = ?')->execute([Util::now(), $playlistId]);
    }
}
