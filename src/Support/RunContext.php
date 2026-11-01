<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Closure;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Runs a callback as the person who clicked — locale, guard, user, panel — and then puts the
 * worker back as it was. A queue worker runs the next job in the same process: without the undo the
 * next, unrelated job would run as this user and in their language.
 */
final class RunContext
{
    /**
     * @param array<string, mixed>                 $spec
     * @param Closure(Authenticatable|null): mixed $callback
     */
    public static function within(array $spec, Closure $callback): mixed
    {
        $guardName = is_string($spec['guard'] ?? null) ? $spec['guard'] : Auth::getDefaultDriver();
        $previousLocale = app()->getLocale();
        $previousDefault = Auth::getDefaultDriver();
        $previousPanel = Filament::getCurrentPanel();
        $driver = Auth::guard($guardName);
        $previousUser = $driver->hasUser() ? $driver->user() : null;

        try {
            return $callback(self::actAs($spec, $guardName));
        } finally {
            app()->setLocale($previousLocale);
            Auth::shouldUse($previousDefault);
            Filament::setCurrentPanel($previousPanel);

            if ($previousUser !== null) {
                $driver->setUser($previousUser);
            } elseif (method_exists($driver, 'forgetUser')) {
                $driver->forgetUser();
            }
        }
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function actAs(array $spec, string $guard): ?Authenticatable
    {
        $locale = $spec['locale'] ?? null;

        if (is_string($locale) && $locale !== '') {
            app()->setLocale($locale);
        }

        Auth::shouldUse($guard);

        $panel = null;
        $panelId = $spec['panel'] ?? null;

        if (is_string($panelId)) {
            try {
                $panel = Filament::getPanel($panelId);
            } catch (Throwable) {
                return null;
            }

            Filament::setCurrentPanel($panel);
            Filament::bootCurrentPanel();
        }

        $id = $spec['user'] ?? null;
        $driver = Auth::guard($guard);
        $provider = method_exists($driver, 'getProvider') ? $driver->getProvider() : null;
        $user = $id !== null ? $provider?->retrieveById($id) : null;

        if (!$user instanceof Authenticatable) {
            return null;
        }

        // The click was allowed then; the job runs later. Somebody who lost the panel meanwhile gets nothing.
        if ($panel !== null && $user instanceof FilamentUser && !$user->canAccessPanel($panel)) {
            return null;
        }

        $driver->setUser($user);

        $before = config('filament-xlsx-import.queue.before');

        if (is_callable($before)) {
            $before($user, $panel);
        }

        return $user;
    }
}
