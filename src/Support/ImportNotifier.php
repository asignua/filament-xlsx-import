<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

final class ImportNotifier
{
    /**
     * @param array<string, mixed> $result
     */
    public static function done(string $id, array $result, ?string $root = null): Notification
    {
        $body = __('filament-xlsx-import::filament-xlsx-import.done_body', [
            'created' => number_format((int) $result['created']),
            'updated' => number_format((int) $result['updated']),
            'skipped' => number_format((int) $result['skipped']),
            'failed' => number_format((int) $result['failed']),
        ]);

        $notification = Notification::make()
            ->title(__('filament-xlsx-import::filament-xlsx-import.done_title'))
            ->body($body);

        $result['failed'] > 0 ? $notification->warning() : $notification->success();

        if ($result['has_report'] ?? false) {
            $notification->actions([
                Action::make('report')
                    ->label(__('filament-xlsx-import::filament-xlsx-import.download_report'))
                    ->url(RunStore::url($id, $root))
                    ->button()
                    ->markAsRead(),
            ]);
        }

        return $notification;
    }

    public static function failed(): Notification
    {
        return Notification::make()
            ->danger()
            ->title(__('filament-xlsx-import::filament-xlsx-import.failed_title'))
            ->body(__('filament-xlsx-import::filament-xlsx-import.failed_body'));
    }

    /**
     * Into the user's database notifications — the only channel a job has (a toast needs a
     * session). A panel without `->databaseNotifications()` has nowhere to show it: the rows are
     * still imported, and a log line says so.
     */
    public static function toDatabase(Notification $notification, Authenticatable $user, string $what): void
    {
        if (!(Filament::getCurrentPanel()?->hasDatabaseNotifications() ?? false) || !$user instanceof Model) {
            Log::info("filament-xlsx-import: {$what}, but the panel has no database notifications, so nobody was notified.");

            return;
        }

        $notification->sendToDatabase($user);
    }
}
