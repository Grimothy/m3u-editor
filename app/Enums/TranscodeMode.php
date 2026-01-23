<?php

namespace App\Enums;

enum TranscodeMode: string
{
    case Direct = 'direct';
    case Server = 'server';
    case Local = 'local';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct Stream',
            self::Server => 'Media Server Transcoding',
            self::Local => 'Local FFmpeg Transcoding',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Direct => 'Pass through source without transcoding. Highest quality, requires player compatibility.',
            self::Server => 'Jellyfin/Emby/Plex handles transcoding using its hardware acceleration settings.',
            self::Local => 'M3U Editor transcodes the stream. Uses hardware acceleration if available in container.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Direct => 'heroicon-o-signal',
            self::Server => 'heroicon-o-arrow-path',
            self::Local => 'heroicon-o-film',
        };
    }
}
