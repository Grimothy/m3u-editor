<?php

namespace App\Jobs;

use App\Models\Playlist;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunPlaylistFindReplaceRules implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $rules = collect($this->playlist->find_replace_rules ?? [])
            ->filter(fn (array $rule): bool => $rule['enabled'] ?? false);

        if ($rules->isEmpty()) {
            return;
        }

        $start = now();
        $channelRulesRun = 0;
        $seriesRulesRun = 0;

        foreach ($rules as $rule) {
            $target = $rule['target'] ?? 'channels';

            if ($target === 'channels') {
                (new ChannelFindAndReplace(
                    user_id: $this->playlist->user_id,
                    use_regex: $rule['use_regex'] ?? true,
                    column: $rule['column'] ?? 'title',
                    find_replace: $rule['find_replace'] ?? '',
                    replace_with: $rule['replace_with'] ?? '',
                    all_playlists: false,
                    playlist_id: $this->playlist->id,
                    silent: true,
                ))->handle();
                $channelRulesRun++;
            } elseif ($target === 'series') {
                (new SeriesFindAndReplace(
                    user_id: $this->playlist->user_id,
                    use_regex: $rule['use_regex'] ?? true,
                    column: $rule['column'] ?? 'name',
                    find_replace: $rule['find_replace'] ?? '',
                    replace_with: $rule['replace_with'] ?? '',
                    all_series: false,
                    playlist_id: $this->playlist->id,
                    silent: true,
                ))->handle();
                $seriesRulesRun++;
            }
        }

        $completedIn = round($start->diffInSeconds(now()), 2);
        $user = User::find($this->playlist->user_id);

        $parts = [];
        if ($channelRulesRun > 0) {
            $parts[] = "{$channelRulesRun} channel ".($channelRulesRun === 1 ? 'rule' : 'rules');
        }
        if ($seriesRulesRun > 0) {
            $parts[] = "{$seriesRulesRun} series ".($seriesRulesRun === 1 ? 'rule' : 'rules');
        }
        $summary = implode(' and ', $parts);

        Notification::make()
            ->success()
            ->title('Saved Find & Replace rules completed')
            ->body("Ran {$summary} for \"{$this->playlist->name}\" in {$completedIn}s.")
            ->broadcast($user);
        Notification::make()
            ->success()
            ->title('Saved Find & Replace rules completed')
            ->body("Ran {$summary} for playlist \"{$this->playlist->name}\". Completed in {$completedIn} seconds.")
            ->sendToDatabase($user);
    }
}
