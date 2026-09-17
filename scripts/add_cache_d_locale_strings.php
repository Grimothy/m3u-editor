<?php

declare(strict_types=1);

/**
 * One-shot locale merger for PR D's new cache UI strings.
 *
 * Reads each lang/{locale}.json, inserts the PR D keys (English source +
 * per-locale translation where one is provided), then re-sorts the JSON
 * alphabetically by key and writes back.
 *
 * Run from the repo root: php scripts/add_cache_d_locale_strings.php
 */
const LOCALES = ['de', 'en', 'es', 'fr', 'zh_CN'];

// PR D keys + translations. Source language is 'en'.
// Any locale without a specific translation falls back to the English value
// (identity), so the keys are still discoverable and a future translator can
// refine them without breaking the page.
$strings = [
    // Page / navigation
    'Cache' => [
        'en' => 'Cache',
        'de' => 'Cache',
        'es' => 'Caché',
        'fr' => 'Cache',
        'zh_CN' => '缓存',
    ],
    'Cache Activity' => [
        'en' => 'Cache Activity',
        'de' => 'Cache-Aktivität',
        'es' => 'Actividad de caché',
        'fr' => 'Activité du cache',
        'zh_CN' => '缓存活动',
    ],
    'Cache Now' => [
        'en' => 'Cache Now',
        'de' => 'Jetzt cachen',
        'es' => 'Cachear ahora',
        'fr' => 'Mettre en cache',
        'zh_CN' => '立即缓存',
    ],
    'Cache download queued' => [
        'en' => 'Cache download queued',
        'de' => 'Cache-Download eingereiht',
        'es' => 'Descarga de caché en cola',
        'fr' => 'Téléchargement du cache mis en file',
        'zh_CN' => '缓存下载已加入队列',
    ],
    'Cache now' => [
        'en' => 'Cache now',
        'de' => 'Jetzt cachen',
        'es' => 'Cachear ahora',
        'fr' => 'Mettre en cache',
        'zh_CN' => '立即缓存',
    ],
    'Cache retention mode' => [
        'en' => 'Cache retention mode',
        'de' => 'Cache-Aufbewahrungsmodus',
        'es' => 'Modo de retención de caché',
        'fr' => 'Mode de rétention du cache',
        'zh_CN' => '缓存保留模式',
    ],
    'Cache this episode?' => [
        'en' => 'Cache this episode?',
        'de' => 'Diese Folge cachen?',
        'es' => '¿Cachear este episodio?',
        'fr' => 'Mettre cet épisode en cache ?',
        'zh_CN' => '缓存本集吗？',
    ],
    'Cache this VOD?' => [
        'en' => 'Cache this VOD?',
        'de' => 'Diesen VOD cachen?',
        'es' => '¿Cachear este VOD?',
        'fr' => 'Mettre ce VOD en cache ?',
        'zh_CN' => '缓存此 VOD 吗？',
    ],
    'Cached' => [
        'en' => 'Cached',
        'de' => 'Gecacht',
        'es' => 'En caché',
        'fr' => 'En cache',
        'zh_CN' => '已缓存',
    ],
    'Cached Download' => [
        'en' => 'Cached Download',
        'de' => 'Gecachter Download',
        'es' => 'Descarga en caché',
        'fr' => 'Téléchargement en cache',
        'zh_CN' => '已缓存下载',
    ],
    'Cached Downloads' => [
        'en' => 'Cached Downloads',
        'de' => 'Gecachte Downloads',
        'es' => 'Descargas en caché',
        'fr' => 'Téléchargements en cache',
        'zh_CN' => '已缓存下载',
    ],
    'Cached file available. Playback will use the local cache.' => [
        'en' => 'Cached file available. Playback will use the local cache.',
        'de' => 'Gecachte Datei verfügbar. Die Wiedergabe nutzt den lokalen Cache.',
        'es' => 'Archivo en caché disponible. La reproducción usará el caché local.',
        'fr' => 'Fichier en cache disponible. La lecture utilisera le cache local.',
        'zh_CN' => '缓存文件可用。播放将使用本地缓存。',
    ],
    'Cached file deleted' => [
        'en' => 'Cached file deleted',
        'de' => 'Gecachte Datei gelöscht',
        'es' => 'Archivo en caché eliminado',
        'fr' => 'Fichier en cache supprimé',
        'zh_CN' => '缓存文件已删除',
    ],
    'Cancelled :count downloads' => [
        'en' => 'Cancelled :count downloads',
        'de' => ':count Downloads abgebrochen',
        'es' => ':count descargas canceladas',
        'fr' => ':count téléchargements annulés',
        'zh_CN' => '已取消 :count 个下载',
    ],
    'Cancelled 1 download' => [
        'en' => 'Cancelled 1 download',
        'de' => '1 Download abgebrochen',
        'es' => '1 descarga cancelada',
        'fr' => '1 téléchargement annulé',
        'zh_CN' => '已取消 1 个下载',
    ],
    'Cancel download' => [
        'en' => 'Cancel download',
        'de' => 'Download abbrechen',
        'es' => 'Cancelar descarga',
        'fr' => 'Annuler le téléchargement',
        'zh_CN' => '取消下载',
    ],
    'Cancel selected' => [
        'en' => 'Cancel selected',
        'de' => 'Auswahl abbrechen',
        'es' => 'Cancelar selección',
        'fr' => 'Annuler la sélection',
        'zh_CN' => '取消所选',
    ],
    'Cancel selected in-flight downloads?' => [
        'en' => 'Cancel selected in-flight downloads?',
        'de' => 'Ausgewählte laufende Downloads abbrechen?',
        'es' => '¿Cancelar las descargas en curso seleccionadas?',
        'fr' => 'Annuler les téléchargements en cours sélectionnés ?',
        'zh_CN' => '取消所选进行中的下载吗？',
    ],
    'Cancel this in-flight download?' => [
        'en' => 'Cancel this in-flight download?',
        'de' => 'Diesen laufenden Download abbrechen?',
        'es' => '¿Cancelar esta descarga en curso?',
        'fr' => 'Annuler ce téléchargement en cours ?',
        'zh_CN' => '取消此进行中的下载吗？',
    ],
    'Configure the standalone per-Channel / per-Episode content cache. When enabled, the Xtream API serves cached files for completed downloads and the dispatch path can lazily cache new content on first play.' => [
        'en' => 'Configure the standalone per-Channel / per-Episode content cache. When enabled, the Xtream API serves cached files for completed downloads and the dispatch path can lazily cache new content on first play.',
        'de' => 'Konfiguriere den eigenständigen Cache pro Kanal/Folge. Wenn aktiviert, liefert die Xtream-API zwischengespeicherte Dateien für abgeschlossene Downloads, und der Dispatcher kann neue Inhalte beim ersten Abruf faul cachen.',
        'es' => 'Configura la caché independiente por Canal/Episodio. Cuando está activa, la API Xtream sirve archivos en caché para descargas completadas y el dispatcher puede cachear perezosamente contenido nuevo en la primera reproducción.',
        'fr' => 'Configurez le cache autonome par Chaîne/Épisode. Lorsqu\'il est activé, l\'API Xtream sert les fichiers en cache pour les téléchargements terminés et le dispatcher peut mettre en cache paresseusement le nouveau contenu dès la première lecture.',
        'zh_CN' => '配置独立的每频道/每剧集缓存。启用后，Xtream API 将为已完成的下载提供缓存文件，并且分发路径可在首次播放时惰性缓存新内容。',
    ],
    'Could not queue cache' => [
        'en' => 'Could not queue cache',
        'de' => 'Cache konnte nicht eingereiht werden',
        'es' => 'No se pudo poner en cola la caché',
        'fr' => 'Impossible de mettre le cache en file',
        'zh_CN' => '无法将缓存加入队列',
    ],
    'Could not retry' => [
        'en' => 'Could not retry',
        'de' => 'Wiederholung nicht möglich',
        'es' => 'No se pudo reintentar',
        'fr' => 'Impossible de réessayer',
        'zh_CN' => '无法重试',
    ],
    'Default value for the per-playlist "Share cache across playlists" toggle. When on, a cached file owned by this playlist is reused by other playlists pointing at the same content (subject to per-playlist overrides).' => [
        'en' => 'Default value for the per-playlist "Share cache across playlists" toggle. When on, a cached file owned by this playlist is reused by other playlists pointing at the same content (subject to per-playlist overrides).',
        'de' => 'Standardwert für den Schalter "Cache zwischen Wiedergabelisten teilen". Wenn aktiviert, wird eine von dieser Wiedergabeliste besessene Cache-Datei von anderen Wiedergabelisten mit demselben Inhalt wiederverwendet (vorbehaltlich Überschreibungen pro Wiedergabeliste).',
        'es' => 'Valor por defecto del interruptor "Compartir caché entre listas". Cuando está activo, un archivo en caché propiedad de esta lista es reutilizado por otras listas que apuntan al mismo contenido (sujeto a overrides por lista).',
        'fr' => 'Valeur par défaut du bouton "Partager le cache entre les listes de lecture". Lorsqu\'il est activé, un fichier en cache appartenant à cette liste est réutilisé par les autres listes pointant vers le même contenu (sous réserve des remplacements par liste).',
        'zh_CN' => '"跨播放列表共享缓存" 开关的默认值。启用后，由该播放列表拥有的缓存文件将被指向相同内容的其他播放列表复用（受每个播放列表覆盖设置约束）。',
    ],
    'Delete cache' => [
        'en' => 'Delete cache',
        'de' => 'Cache löschen',
        'es' => 'Eliminar caché',
        'fr' => 'Supprimer le cache',
        'zh_CN' => '删除缓存',
    ],
    'Delete selected' => [
        'en' => 'Delete selected',
        'de' => 'Auswahl löschen',
        'es' => 'Eliminar selección',
        'fr' => 'Supprimer la sélection',
        'zh_CN' => '删除所选',
    ],
    'Delete selected cached files?' => [
        'en' => 'Delete selected cached files?',
        'de' => 'Ausgewählte Cache-Dateien löschen?',
        'es' => '¿Eliminar los archivos en caché seleccionados?',
        'fr' => 'Supprimer les fichiers en cache sélectionnés ?',
        'zh_CN' => '删除所选的缓存文件吗？',
    ],
    'Delete this cached file?' => [
        'en' => 'Delete this cached file?',
        'de' => 'Diese Cache-Datei löschen?',
        'es' => '¿Eliminar este archivo en caché?',
        'fr' => 'Supprimer ce fichier en cache ?',
        'zh_CN' => '删除此缓存文件吗？',
    ],
    'Deleted :count cached files' => [
        'en' => 'Deleted :count cached files',
        'de' => ':count Cache-Dateien gelöscht',
        'es' => ':count archivos en caché eliminados',
        'fr' => ':count fichiers en cache supprimés',
        'zh_CN' => '已删除 :count 个缓存文件',
    ],
    'Deleted 1 cached file' => [
        'en' => 'Deleted 1 cached file',
        'de' => '1 Cache-Datei gelöscht',
        'es' => '1 archivo en caché eliminado',
        'fr' => '1 fichier en cache supprimé',
        'zh_CN' => '已删除 1 个缓存文件',
    ],
    'Download cancelled' => [
        'en' => 'Download cancelled',
        'de' => 'Download abgebrochen',
        'es' => 'Descarga cancelada',
        'fr' => 'Téléchargement annulé',
        'zh_CN' => '下载已取消',
    ],
    'Download failure' => [
        'en' => 'Download failure',
        'de' => 'Download-Fehler',
        'es' => 'Fallo de descarga',
        'fr' => 'Échec du téléchargement',
        'zh_CN' => '下载失败',
    ],
    'Enable cache' => [
        'en' => 'Enable cache',
        'de' => 'Cache aktivieren',
        'es' => 'Activar caché',
        'fr' => 'Activer le cache',
        'zh_CN' => '启用缓存',
    ],
    'Episode :seasonx:episode' => [
        'en' => 'Episode :seasonx:episode',
        'de' => 'Folge :seasonx:episode',
        'es' => 'Episodio :seasonx:episode',
        'fr' => 'Épisode :seasonx:episode',
        'zh_CN' => '剧集 :seasonx:episode',
    ],
    'Error message' => [
        'en' => 'Error message',
        'de' => 'Fehlermeldung',
        'es' => 'Mensaje de error',
        'fr' => 'Message d\'erreur',
        'zh_CN' => '错误信息',
    ],
    'How cached files are kept across the cleanup job. "Never expire" disables automatic cleanup. "Time-based" honors each row\'s age threshold. "Manual" requires explicit deletion.' => [
        'en' => 'How cached files are kept across the cleanup job. "Never expire" disables automatic cleanup. "Time-based" honors each row\'s age threshold. "Manual" requires explicit deletion.',
        'de' => 'Wie Cache-Dateien vom Aufräumjob behandelt werden. "Nie ablaufen" deaktiviert die automatische Bereinigung. "Zeitbasiert" beachtet den Alters-Schwellwert jeder Zeile. "Manuell" erfordert explizites Löschen.',
        'es' => 'Cómo se mantienen los archivos en caché en el trabajo de limpieza. "Nunca expirar" desactiva la limpieza automática. "Basado en tiempo" respeta el umbral de antigüedad de cada fila. "Manual" requiere borrado explícito.',
        'fr' => 'Comment les fichiers en cache sont conservés par le job de nettoyage. "Ne jamais expirer" désactive le nettoyage automatique. "Basé sur le temps" respecte le seuil d\'âge de chaque ligne. "Manuel" nécessite une suppression explicite.',
        'zh_CN' => '缓存文件在清理任务中的保留方式。"永不过期" 禁用自动清理。"基于时间" 按每行的时效阈值。"手动" 需要显式删除。',
    ],
    'Last failed' => [
        'en' => 'Last failed',
        'de' => 'Zuletzt fehlgeschlagen',
        'es' => 'Último fallo',
        'fr' => 'Dernier échec',
        'zh_CN' => '最后失败',
    ],
    'Live download progress for cached VOD channels and Series episodes. Pending, Downloading, Completed, and Failed rows across your playlists are listed below.' => [
        'en' => 'Live download progress for cached VOD channels and Series episodes. Pending, Downloading, Completed, and Failed rows across your playlists are listed below.',
        'de' => 'Live-Download-Fortschritt für gecachte VOD-Kanäle und Serien-Folgen. Zeilen mit Status Pending, Downloading, Completed und Failed aus deinen Wiedergabelisten sind unten aufgeführt.',
        'es' => 'Progreso de descarga en vivo para canales VOD y episodios de series en caché. Las filas en Pending, Downloading, Completed y Failed de tus listas aparecen abajo.',
        'fr' => 'Progression du téléchargement en direct pour les chaînes VOD et épisodes de séries en cache. Les lignes en Pending, Downloading, Completed et Failed de vos listes sont listées ci-dessous.',
        'zh_CN' => '已缓存 VOD 频道和系列剧集下载的实时进度。你的播放列表中 Pending、Downloading、Completed 和 Failed 行在下方列出。',
    ],
    'Live download progress for cached VOD channels and Series episodes. Pending, Downloading, Completed, and Failed rows across your playlists are listed here.' => [
        'en' => 'Live download progress for cached VOD channels and Series episodes. Pending, Downloading, Completed, and Failed rows across your playlists are listed here.',
        'de' => 'Live-Download-Fortschritt für gecachte VOD-Kanäle und Serien-Folgen. Zeilen mit Status Pending, Downloading, Completed und Failed aus deinen Wiedergabelisten sind hier aufgeführt.',
        'es' => 'Progreso de descarga en vivo para canales VOD y episodios de series en caché. Las filas en Pending, Downloading, Completed y Failed de tus listas aparecen aquí.',
        'fr' => 'Progression du téléchargement en direct pour les chaînes VOD et épisodes de séries en cache. Les lignes en Pending, Downloading, Completed et Failed de vos listes sont listées ici.',
        'zh_CN' => '已缓存 VOD 频道和系列剧集下载的实时进度。你的播放列表中 Pending、Downloading、Completed 和 Failed 行在此列出。',
    ],
    'Manual' => [
        'en' => 'Manual',
        'de' => 'Manuell',
        'es' => 'Manual',
        'fr' => 'Manuel',
        'zh_CN' => '手动',
    ],
    'Master switch for the standalone content cache. When off, the cache-hit gate stops serving cached files and the dispatcher stops queuing new downloads. Existing cached files are kept but ignored.' => [
        'en' => 'Master switch for the standalone content cache. When off, the cache-hit gate stops serving cached files and the dispatcher stops queuing new downloads. Existing cached files are kept but ignored.',
        'de' => 'Hauptschalter für den eigenständigen Inhaltscache. Wenn aus, liefert die Cache-Treffer-Logik keine Dateien mehr und der Dispatcher reiht keine neuen Downloads ein. Bestehende Cache-Dateien bleiben erhalten, werden aber ignoriert.',
        'es' => 'Interruptor principal de la caché de contenido independiente. Cuando está apagado, la puerta de caché-hit deja de servir archivos y el dispatcher deja de poner en cola nuevas descargas. Los archivos existentes se conservan pero se ignoran.',
        'fr' => 'Interrupteur principal du cache de contenu autonome. Lorsqu\'il est désactivé, la logique de cache-hit ne sert plus les fichiers et le dispatcher n\'enfile plus de nouveaux téléchargements. Les fichiers existants sont conservés mais ignorés.',
        'zh_CN' => '独立内容缓存的主开关。关闭后，缓存命中逻辑停止提供缓存文件，分发器也停止排队新下载。已有缓存文件保留但被忽略。',
    ],
    'Never expire' => [
        'en' => 'Never expire',
        'de' => 'Nie ablaufen',
        'es' => 'Nunca expirar',
        'fr' => 'Ne jamais expirer',
        'zh_CN' => '永不过期',
    ],
    'No cache activity yet' => [
        'en' => 'No cache activity yet',
        'de' => 'Noch keine Cache-Aktivität',
        'es' => 'Aún no hay actividad de caché',
        'fr' => 'Aucune activité de cache pour le moment',
        'zh_CN' => '暂无缓存活动',
    ],
    'No error message recorded.' => [
        'en' => 'No error message recorded.',
        'de' => 'Keine Fehlermeldung aufgezeichnet.',
        'es' => 'No se ha registrado ningún mensaje de error.',
        'fr' => 'Aucun message d\'erreur enregistré.',
        'zh_CN' => '未记录错误信息。',
    ],
    'No source Channel or Episode could be resolved for this row. The underlying content may have been removed.' => [
        'en' => 'No source Channel or Episode could be resolved for this row. The underlying content may have been removed.',
        'de' => 'Für diese Zeile konnte kein Quell-Kanal/Quelle-Folge ermittelt werden. Der zugrunde liegende Inhalt wurde möglicherweise entfernt.',
        'es' => 'No se pudo resolver un Canal o Episodio de origen para esta fila. El contenido subyacente puede haber sido eliminado.',
        'fr' => 'Aucune chaîne ou épisode source n\'a pu être résolu pour cette ligne. Le contenu sous-jacent a peut-être été supprimé.',
        'zh_CN' => '无法为该行解析源频道或源剧集。基础内容可能已被移除。',
    ],
    'Not cached. Use "Cache Now" to download the file for offline playback.' => [
        'en' => 'Not cached. Use "Cache Now" to download the file for offline playback.',
        'de' => 'Nicht gecacht. Benutze "Jetzt cachen", um die Datei für die Offline-Wiedergabe herunterzuladen.',
        'es' => 'No en caché. Usa "Cachear ahora" para descargar el archivo para reproducción sin conexión.',
        'fr' => 'Pas en cache. Utilisez "Mettre en cache" pour télécharger le fichier pour la lecture hors ligne.',
        'zh_CN' => '未缓存。使用 "立即缓存" 以下载该文件以供离线播放。',
    ],
    'Override the global retention mode (set in Settings > Integrations > Cache) for this playlist. Leave empty to fall back to the global setting.' => [
        'en' => 'Override the global retention mode (set in Settings > Integrations > Cache) for this playlist. Leave empty to fall back to the global setting.',
        'de' => 'Überschreibe den globalen Aufbewahrungsmodus (Einstellungen > Integrationen > Cache) für diese Wiedergabeliste. Leer lassen, um die globale Einstellung zu verwenden.',
        'es' => 'Anula el modo de retención global (Ajustes > Integraciones > Caché) para esta lista. Déjalo vacío para usar el ajuste global.',
        'fr' => 'Remplace le mode de rétention global (Paramètres > Intégrations > Cache) pour cette liste. Laissez vide pour utiliser le paramètre global.',
        'zh_CN' => '为此播放列表覆盖全局保留模式（在 设置 > 集成 > 缓存 中设置）。留空则回退到全局设置。',
    ],
    'Removes the row and storage file for every selected row. Any row still Pending or Downloading is also signaled to abort and stops within a few seconds.' => [
        'en' => 'Removes the row and storage file for every selected row. Any row still Pending or Downloading is also signaled to abort and stops within a few seconds.',
        'de' => 'Entfernt die Zeile und Speicherdatei für jede ausgewählte Zeile. Noch laufende Zeilen (Pending/Downloading) werden zusätzlich zum Abbruch signalisiert und stoppen innerhalb weniger Sekunden.',
        'es' => 'Elimina la fila y el archivo de almacenamiento de cada fila seleccionada. Cualquier fila aún en Pending o Downloading también recibe la señal de abortar y se detiene en unos segundos.',
        'fr' => 'Supprime la ligne et le fichier de stockage pour chaque ligne sélectionnée. Toute ligne encore en Pending ou Downloading reçoit également le signal d\'abandon et s\'arrête en quelques secondes.',
        'zh_CN' => '为每个所选行删除该行和存储文件。任何仍处于 Pending 或 Downloading 状态的行也会收到中止信号并在数秒内停止。',
    ],
    'Removes the tracking row and any partial storage file for every Pending or Downloading row in the selection, and stops the transfers. Failed and Completed rows are skipped.' => [
        'en' => 'Removes the tracking row and any partial storage file for every Pending or Downloading row in the selection, and stops the transfers. Failed and Completed rows are skipped.',
        'de' => 'Entfernt die Tracking-Zeile und alle Teilspeicherdateien für jede Pending- oder Downloading-Zeile in der Auswahl und stoppt die Übertragungen. Failed- und Completed-Zeilen werden übersprungen.',
        'es' => 'Elimina la fila de seguimiento y cualquier archivo parcial para cada fila Pending o Downloading de la selección, y detiene las transferencias. Las filas Failed y Completed se omiten.',
        'fr' => 'Supprime la ligne de suivi et tout fichier partiel pour chaque ligne Pending ou Downloading de la sélection, et arrête les transferts. Les lignes Failed et Completed sont ignorées.',
        'zh_CN' => '为选区中每个 Pending 或 Downloading 行删除跟踪行和任何部分存储文件，并停止传输。Failed 和 Completed 行将被跳过。',
    ],
    'Removes the tracking row and any partial storage file, and stops the transfer. An active download stops within a few seconds; a not-yet-started one may rarely still begin if a worker was already about to pick it up.' => [
        'en' => 'Removes the tracking row and any partial storage file, and stops the transfer. An active download stops within a few seconds; a not-yet-started one may rarely still begin if a worker was already about to pick it up.',
        'de' => 'Entfernt die Tracking-Zeile und alle Teilspeicherdateien und stoppt die Übertragung. Ein aktiver Download stoppt innerhalb weniger Sekunden; ein noch nicht gestarteter kann in seltenen Fällen trotzdem beginnen, falls ein Worker schon zur Ausführung bereitstand.',
        'es' => 'Elimina la fila de seguimiento y cualquier archivo parcial, y detiene la transferencia. Una descarga activa se detiene en unos segundos; una aún no iniciada puede, en raras ocasiones, comenzar si un worker ya estaba a punto de recogerla.',
        'fr' => 'Supprime la ligne de suivi et tout fichier partiel, et arrête le transfert. Un téléchargement actif s\'arrête en quelques secondes ; un téléchargement non démarré peut rarement encore commencer si un worker était sur le point de le prendre.',
        'zh_CN' => '删除跟踪行和任何部分存储文件，并停止传输。活跃下载将在数秒内停止；尚未开始的下载在极少数情况下可能仍会启动（如果 worker 已准备拾取）。',
    ],
    'Retry download' => [
        'en' => 'Retry download',
        'de' => 'Download wiederholen',
        'es' => 'Reintentar descarga',
        'fr' => 'Réessayer le téléchargement',
        'zh_CN' => '重试下载',
    ],
    'Retry queued' => [
        'en' => 'Retry queued',
        'de' => 'Wiederholung eingereiht',
        'es' => 'Reintento en cola',
        'fr' => 'Nouvelle tentative en file',
        'zh_CN' => '重试已加入队列',
    ],
    'Retry queued for 1 row' => [
        'en' => 'Retry queued for 1 row',
        'de' => 'Wiederholung für 1 Zeile eingereiht',
        'es' => 'Reintento en cola para 1 fila',
        'fr' => 'Nouvelle tentative en file pour 1 ligne',
        'zh_CN' => '已为 1 行排队重试',
    ],
    'Retry queued for :count rows' => [
        'en' => 'Retry queued for :count rows',
        'de' => 'Wiederholung für :count Zeilen eingereiht',
        'es' => 'Reintento en cola para :count filas',
        'fr' => 'Nouvelle tentative en file pour :count lignes',
        'zh_CN' => '已为 :count 行排队重试',
    ],
    'Retry selected' => [
        'en' => 'Retry selected',
        'de' => 'Auswahl wiederholen',
        'es' => 'Reintentar selección',
        'fr' => 'Réessayer la sélection',
        'zh_CN' => '重试所选',
    ],
    'Retry selected downloads?' => [
        'en' => 'Retry selected downloads?',
        'de' => 'Ausgewählte Downloads wiederholen?',
        'es' => '¿Reintentar las descargas seleccionadas?',
        'fr' => 'Réessayer les téléchargements sélectionnés ?',
        'zh_CN' => '重试所选下载吗？',
    ],
    'Retry this download?' => [
        'en' => 'Retry this download?',
        'de' => 'Diesen Download wiederholen?',
        'es' => '¿Reintentar esta descarga?',
        'fr' => 'Réessayer ce téléchargement ?',
        'zh_CN' => '重试此下载吗？',
    ],
    'Share cache across playlists' => [
        'en' => 'Share cache across playlists',
        'de' => 'Cache zwischen Wiedergabelisten teilen',
        'es' => 'Compartir caché entre listas',
        'fr' => 'Partager le cache entre les listes',
        'zh_CN' => '跨播放列表共享缓存',
    ],
    'Share cache across playlists by default' => [
        'en' => 'Share cache across playlists by default',
        'de' => 'Cache standardmäßig zwischen Wiedergabelisten teilen',
        'es' => 'Compartir caché entre listas por defecto',
        'fr' => 'Partager le cache entre les listes par défaut',
        'zh_CN' => '默认跨播放列表共享缓存',
    ],
    'The cached file was removed from Storage.' => [
        'en' => 'The cached file was removed from Storage.',
        'de' => 'Die gecachte Datei wurde aus dem Speicher entfernt.',
        'es' => 'El archivo en caché se eliminó del almacenamiento.',
        'fr' => 'Le fichier en cache a été supprimé du stockage.',
        'zh_CN' => '缓存文件已从存储中移除。',
    ],
    'The row and any partial file were removed.' => [
        'en' => 'The row and any partial file were removed.',
        'de' => 'Die Zeile und alle Teildateien wurden entfernt.',
        'es' => 'La fila y cualquier archivo parcial fueron eliminados.',
        'fr' => 'La ligne et tout fichier partiel ont été supprimés.',
        'zh_CN' => '该行和任何部分文件均已移除。',
    ],
    'This removes the file from Storage and deletes the cached_content_files row. Playback will fall back to the live source.' => [
        'en' => 'This removes the file from Storage and deletes the cached_content_files row. Playback will fall back to the live source.',
        'de' => 'Dadurch wird die Datei aus dem Speicher entfernt und die cached_content_files-Zeile gelöscht. Die Wiedergabe fällt auf die Live-Quelle zurück.',
        'es' => 'Esto elimina el archivo del almacenamiento y borra la fila de cached_content_files. La reproducción volverá a la fuente en vivo.',
        'fr' => 'Cela supprime le fichier du stockage et efface la ligne cached_content_files. La lecture repassera sur la source en direct.',
        'zh_CN' => '这将从存储中删除文件并删除 cached_content_files 行。播放将回退到直播源。',
    ],
    'Time-based' => [
        'en' => 'Time-based',
        'de' => 'Zeitbasiert',
        'es' => 'Basado en tiempo',
        'fr' => 'Basé sur le temps',
        'zh_CN' => '基于时间',
    ],
    'Total failures' => [
        'en' => 'Total failures',
        'de' => 'Gesamtfehler',
        'es' => 'Fallos totales',
        'fr' => 'Total des échecs',
        'zh_CN' => '失败总数',
    ],
    'Track progress in this widget.' => [
        'en' => 'Track progress in this widget.',
        'de' => 'Verfolge den Fortschritt in diesem Widget.',
        'es' => 'Sigue el progreso en este widget.',
        'fr' => 'Suivez la progression dans ce widget.',
        'zh_CN' => '在此小组件中跟踪进度。',
    ],
    'Track progress on the Cached Downloads page.' => [
        'en' => 'Track progress on the Cached Downloads page.',
        'de' => 'Verfolge den Fortschritt auf der Seite "Gecachte Downloads".',
        'es' => 'Sigue el progreso en la página de Descargas en caché.',
        'fr' => 'Suivez la progression sur la page Téléchargements en cache.',
        'zh_CN' => '在 "已缓存下载" 页面上跟踪进度。',
    ],
    'Unknown error.' => [
        'en' => 'Unknown error.',
        'de' => 'Unbekannter Fehler.',
        'es' => 'Error desconocido.',
        'fr' => 'Erreur inconnue.',
        'zh_CN' => '未知错误。',
    ],
    'Use global default (:mode)' => [
        'en' => 'Use global default (:mode)',
        'de' => 'Globale Standardeinstellung verwenden (:mode)',
        'es' => 'Usar el valor por defecto global (:mode)',
        'fr' => 'Utiliser la valeur par défaut globale (:mode)',
        'zh_CN' => '使用全局默认值 (:mode)',
    ],
    'VOD' => [
        'en' => 'VOD',
        'de' => 'VOD',
        'es' => 'VOD',
        'fr' => 'VOD',
        'zh_CN' => '点播',
    ],
    'View error' => [
        'en' => 'View error',
        'de' => 'Fehler anzeigen',
        'es' => 'Ver error',
        'fr' => 'Voir l\'erreur',
        'zh_CN' => '查看错误',
    ],
    'When enabled, a cached file owned by this playlist is reused by other playlists pointing at the same content (subject to the source playlist also having sharing enabled). Disabling this hides this playlist\'s cached files from the cross-playlist dedup lookup.' => [
        'en' => 'When enabled, a cached file owned by this playlist is reused by other playlists pointing at the same content (subject to the source playlist also having sharing enabled). Disabling this hides this playlist\'s cached files from the cross-playlist dedup lookup.',
        'de' => 'Wenn aktiviert, wird eine von dieser Wiedergabeliste besessene Cache-Datei von anderen Wiedergabelisten mit demselben Inhalt wiederverwendet (vorausgesetzt, auch die Quell-Wiedergabeliste hat das Teilen aktiviert). Das Deaktivieren versteckt die Cache-Dateien dieser Wiedergabeliste vor der plattformübergreifenden Dedup-Suche.',
        'es' => 'Cuando está activo, un archivo en caché propiedad de esta lista es reutilizado por otras listas que apuntan al mismo contenido (siempre que la lista de origen también tenga el intercambio activo). Desactivarlo oculta los archivos en caché de esta lista del lookup de deduplicación entre listas.',
        'fr' => 'Lorsqu\'il est activé, un fichier en cache appartenant à cette liste est réutilisé par les autres listes pointant vers le même contenu (à condition que la liste source ait également le partage activé). Le désactiver masque les fichiers en cache de cette liste de la recherche de déduplication inter-listes.',
        'zh_CN' => '启用后，由该播放列表拥有的缓存文件将被指向相同内容的其他播放列表复用（前提是源播放列表也启用了共享）。关闭此选项将在跨播放列表去重查找中隐藏此播放列表的缓存文件。',
    ],
    'A pending or completed cached file already exists for this VOD.' => [
        'en' => 'A pending or completed cached file already exists for this VOD.',
        'de' => 'Für diesen VOD existiert bereits eine ausstehende oder abgeschlossene Cache-Datei.',
        'es' => 'Ya existe un archivo en caché pendiente o completado para este VOD.',
        'fr' => 'Un fichier en cache en attente ou terminé existe déjà pour ce VOD.',
        'zh_CN' => '此 VOD 已存在待处理或已完成的缓存文件。',
    ],
    'A pending or completed cached file already exists for this episode.' => [
        'en' => 'A pending or completed cached file already exists for this episode.',
        'de' => 'Für diese Folge existiert bereits eine ausstehende oder abgeschlossene Cache-Datei.',
        'es' => 'Ya existe un archivo en caché pendiente o completado para este episodio.',
        'fr' => 'Un fichier en cache en attente ou terminé existe déjà pour cet épisode.',
        'zh_CN' => '此剧集已存在待处理或已完成的缓存文件。',
    ],
    'Dispatch a background job to download ":title" to local storage for offline playback.' => [
        'en' => 'Dispatch a background job to download ":title" to local storage for offline playback.',
        'de' => 'Starte einen Hintergrund-Job, um ":title" für die Offline-Wiedergabe in den lokalen Speicher herunterzuladen.',
        'es' => 'Lanza un trabajo en segundo plano para descargar ":title" al almacenamiento local para reproducción sin conexión.',
        'fr' => 'Lancer une tâche en arrière-plan pour télécharger ":title" dans le stockage local pour la lecture hors ligne.',
        'zh_CN' => '派发后台任务以将 ":title" 下载到本地存储以供离线播放。',
    ],
    'This VOD has no resolvable source URL.' => [
        'en' => 'This VOD has no resolvable source URL.',
        'de' => 'Dieser VOD hat keine auflösbare Quell-URL.',
        'es' => 'Este VOD no tiene una URL de origen resoluble.',
        'fr' => 'Ce VOD n\'a pas d\'URL source résolvable.',
        'zh_CN' => '此 VOD 没有可解析的源 URL。',
    ],
    'This episode has no resolvable source URL.' => [
        'en' => 'This episode has no resolvable source URL.',
        'de' => 'Diese Folge hat keine auflösbare Quell-URL.',
        'es' => 'Este episodio no tiene una URL de origen resoluble.',
        'fr' => 'Cet épisode n\'a pas d\'URL source résolvable.',
        'zh_CN' => '此剧集没有可解析的源 URL。',
    ],
    'Cached content downloads will appear here once the dispatcher runs or you click "Cache Now" on a Channel or Episode row.' => [
        'en' => 'Cached content downloads will appear here once the dispatcher runs or you click "Cache Now" on a Channel or Episode row.',
        'de' => 'Cache-Downloads erscheinen hier, sobald der Dispatcher läuft oder du "Jetzt cachen" in einer Kanal- oder Folge-Zeile klickst.',
        'es' => 'Las descargas en caché aparecerán aquí cuando el dispatcher se ejecute o hagas clic en "Cachear ahora" en una fila de Canal o Episodio.',
        'fr' => 'Les téléchargements en cache apparaîtront ici dès que le dispatcher s\'exécute ou que vous cliquerez sur "Mettre en cache" sur une ligne Chaîne ou Épisode.',
        'zh_CN' => '当分发器运行或在频道/剧集行上点击 "立即缓存" 时，缓存下载将显示在此处。',
    ],
    ':skipped row(s) skipped (not Failed, or no source resolvable).' => [
        'en' => ':skipped row(s) skipped (not Failed, or no source resolvable).',
        'de' => ':skipped Zeile(n) übersprungen (nicht Failed oder keine Quelle auflösbar).',
        'es' => ':skipped fila(s) omitida(s) (no Failed, o sin origen resoluble).',
        'fr' => ':skipped ligne(s) ignorée(s) (non Failed, ou source non résolvable).',
        'zh_CN' => ':skipped 行已跳过（非 Failed，或无可解析的源）。',
    ],
    ':skipped row(s) skipped (not in-flight).' => [
        'en' => ':skipped row(s) skipped (not in-flight).',
        'de' => ':skipped Zeile(n) übersprungen (nicht in Bearbeitung).',
        'es' => ':skipped fila(s) omitida(s) (no en curso).',
        'fr' => ':skipped ligne(s) ignorée(s) (pas en cours).',
        'zh_CN' => ':skipped 行已跳过（不在进行中）。',
    ],
    'Already cached or queued' => [
        'en' => 'Already cached or queued',
        'de' => 'Bereits gecacht oder in der Warteschlange',
        'es' => 'Ya en caché o en cola',
        'fr' => 'Déjà en cache ou en file',
        'zh_CN' => '已缓存或已加入队列',
    ],
];

$repoRoot = dirname(__DIR__);
$totalAdded = 0;
$perFile = [];

foreach (LOCALES as $locale) {
    $path = $repoRoot.'/lang/'.$locale.'.json';
    $original = file_get_contents($path);
    $data = json_decode($original, true, flags: JSON_THROW_ON_ERROR);

    $added = 0;
    foreach ($strings as $key => $translations) {
        if (array_key_exists($key, $data)) {
            // Existing key — leave value untouched to avoid overwriting a
            // more accurate translation with our seed value.
            continue;
        }
        $value = $translations[$locale] ?? $translations['en'] ?? $key;
        $data[$key] = $value;
        $added++;
    }

    // Re-sort alphabetically by key.
    ksort($data, SORT_STRING | SORT_FLAG_CASE);

    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // JSON_PRETTY_PRINT uses 4 spaces; project standard is 4. Ensure trailing newline.
    $encoded .= "\n";
    file_put_contents($path, $encoded);

    $perFile[$locale] = $added;
    $totalAdded += $added;
}

echo "Added {$totalAdded} new keys across ".count(LOCALES)." locales.\n";
foreach ($perFile as $locale => $count) {
    echo "  {$locale}.json: +{$count}\n";
}
