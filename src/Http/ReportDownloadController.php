<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Http;

use Asignua\FilamentXlsxImport\Support\RunStore;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Streams the failed-rows report of a finished import through its signed link, to the user who
 * ran it. A forged signature is a 403; anything a human can run into (expired, somebody else's,
 * pruned) sends them back with a notification.
 */
final class ReportDownloadController
{
    public function __invoke(Request $request, string $id): Response
    {
        abort_unless(URL::hasCorrectSignature($request), 403);

        if (!URL::signatureHasNotExpired($request)) {
            return $this->refuse(null, 'link_expired');
        }

        $meta = RunStore::meta($id);
        $disk = RunStore::disk();
        $file = RunStore::directory().'/'.$id.'/report.xlsx';

        if ($meta === [] || !$disk->exists($file)) {
            return $this->refuse(null, 'file_gone');
        }

        $guard = is_string($meta['guard'] ?? null) ? $meta['guard'] : null;
        $panel = is_string($meta['panel'] ?? null) ? $meta['panel'] : null;

        if (!$this->isOwner($guard, $meta['user'] ?? null) || !$this->mayOpenPanel($guard, $panel)) {
            return $this->refuse($panel, 'link_unusable');
        }

        $name = pathinfo((string) ($meta['file_name'] ?? 'import'), PATHINFO_FILENAME);
        $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '', $name) ?: 'import';

        return $disk->download($file, $name.' - '.__('filament-xlsx-import::filament-xlsx-import.report.file_suffix').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function isOwner(?string $guard, mixed $owner): bool
    {
        try {
            $user = Auth::guard($guard)->id();
        } catch (Throwable) {
            return false;
        }

        return $user !== null && is_scalar($owner) && (string) $user === (string) $owner;
    }

    private function mayOpenPanel(?string $guard, ?string $panel): bool
    {
        if ($panel === null) {
            return true;
        }

        try {
            $user = Auth::guard($guard)->user();

            if (!$user instanceof FilamentUser) {
                return true;
            }

            $target = Filament::getPanel($panel);

            return $target !== null && $user->canAccessPanel($target);
        } catch (Throwable) {
            return false;
        }
    }

    private function refuse(?string $panelId, string $message): RedirectResponse
    {
        Notification::make()->title(__('filament-xlsx-import::filament-xlsx-import.'.$message))->danger()->send();

        try {
            $target = $panelId !== null ? Filament::getPanel($panelId) : Filament::getDefaultPanel();
            $fallback = url($target?->getPath() ?? '/');
        } catch (Throwable) {
            $fallback = url('/');
        }

        return redirect()->back(302, [], $fallback);
    }
}
