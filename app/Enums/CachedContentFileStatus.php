<?php

namespace App\Enums;

enum CachedContentFileStatus: string
{
    case Requested = 'requested';
    case Pending = 'pending';
    case Downloading = 'downloading';
    case Imported = 'imported';
    case Completed = 'completed';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Requested => __('Requested'),
            self::Pending => __('Pending'),
            self::Downloading => __('Downloading'),
            self::Imported => __('Imported, waiting for media server'),
            self::Completed => __('Completed'),
            self::Failed => __('Failed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Requested => 'gray',
            self::Pending => 'info',
            self::Downloading => 'warning',
            self::Imported => 'info',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Requested => 'heroicon-o-paper-airplane',
            self::Pending => 'heroicon-o-clock',
            self::Downloading => 'heroicon-o-arrow-down-tray',
            self::Imported => 'heroicon-o-inbox-arrow-down',
            self::Completed => 'heroicon-o-check-circle',
            self::Failed => 'heroicon-o-x-circle',
        };
    }
}
