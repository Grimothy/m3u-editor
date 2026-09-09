<?php

namespace App\Filament\Clusters\Settings\Pages\Concerns;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Jobs\RestartQueue;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Artisan;

abstract class BaseSettingsPage extends SettingsPage
{
    protected static string $settings = GeneralSettings::class;

    protected static ?string $cluster = SettingsCluster::class;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    /**
     * Shared maintenance actions, rendered in the page header on every settings
     * sub-page (Page::getHeaderActions() falls back to getActions()).
     */
    protected function getActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('test_websocket')
                    ->label(__('Test WebSocket'))
                    ->icon('heroicon-o-signal')
                    ->color('gray')
                    ->modalWidth('md')
                    ->schema([
                        TextInput::make('message')
                            ->label(__('Message'))
                            ->required()
                            ->default('Testing WebSocket connection')
                            ->helperText(__('This message will be sent to the WebSocket server and displayed as a pop-up notification. If you do not see a notification shortly after sending, there is likely an issue with your WebSocket configuration.')),
                    ])
                    ->action(function (array $data): void {
                        Notification::make()
                            ->success()
                            ->title(__('WebSocket Connection Test'))
                            ->body($data['message'])
                            ->persistent()
                            ->broadcast(auth()->user());
                    }),
                Action::make('clear_expired_logo_cache')
                    ->label(__('Clear Expired Logo Cache'))
                    ->action(fn () => Artisan::call('app:logo-cleanup --force'))
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Expired logo cache cleared'))
                            ->body(__('Expired logo cache files were removed successfully.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->color('warning')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-trash')
                    ->modalIcon('heroicon-o-trash')
                    ->modalDescription(__('Only expired logo cache entries (those older than 30 days). If permanent cache is enabled, nothing will be removed.'))
                    ->modalSubmitActionLabel(__('Clear expired cache')),
                Action::make('clear_logo_cache')
                    ->label(__('Clear All Logo Cache'))
                    ->action(fn () => Artisan::call('app:logo-cleanup --force --all'))
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Logo cache cleared'))
                            ->body(__('The logo cache has been cleared. Logos will be fetched again on next request wherever logo proxy is enabled.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->color('danger')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-exclamation-triangle')
                    ->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalDescription(__('Clearing the logo cache will remove all cached logo images. If permanent cache is enabled, it will be ignored. This action cannot be undone.'))
                    ->modalSubmitActionLabel(__('I understand, clear now')),
                Action::make('reset_queue')
                    ->label(__('Reset Queue'))
                    ->action(function (Dispatcher $dispatcher): void {
                        $dispatcher->dispatch(new RestartQueue);
                    })
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Queue reset'))
                            ->body(__('The queue workers have been restarted and any pending jobs flushed. You may need to manually sync any Playlists or EPGs that were in progress.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->color('danger')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-exclamation-triangle')
                    ->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalDescription(__('Resetting the queue will restart the queue workers and flush any pending jobs. Any syncs or background processes will be stopped and removed. Only perform this action if you are having sync issues.'))
                    ->modalSubmitActionLabel(__('I understand, reset now')),
            ])->button()->color('gray')->label(__('Actions')),
        ];
    }

    public function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__('Settings saved'))
            ->body(__('Your preferences have been saved successfully.'));
    }
}
