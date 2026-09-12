<?php

namespace App\Filament\Resources\DvrRecordingRules\Pages;

use App\Filament\Resources\DvrRecordingRules\DvrRecordingRuleResource;
use App\Jobs\DvrSchedulerTick;
use App\Models\PlaylistAuth;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateDvrRecordingRule extends CreateRecord
{
    protected static string $resource = DvrRecordingRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! empty($data['playlist_auth_id'])) {
            $auth = PlaylistAuth::find($data['playlist_auth_id']);
            if (! $auth || (int) $auth->user_id !== (int) Auth::id()) {
                throw ValidationException::withMessages([
                    'playlist_auth_id' => __('Selected auth does not belong to your account.'),
                ]);
            }
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        // Dispatch immediate scheduler tick so matching recordings materialise without waiting up to 60s.
        DvrSchedulerTick::dispatch();
    }
}
