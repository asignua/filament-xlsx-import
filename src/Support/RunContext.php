<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Closure;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Runs a callback as the person who clicked — locale, guard, user, panel — and then puts the
 * worker back as it was. A queue worker runs the next job in the same process: without the undo the
 * next, unrelated job would run as this user and in their language.
 */
final class RunContext
{
    private static bool $beforeRan = false;

    /**
     * @param array<string, mixed>                 $spec
     * @param Closure(Authenticatable|null): mixed $callback
     */
    public static function within(array $spec, Closure $callback): mixed
    {
        self::$beforeRan = false;
        $guardName = is_string($spec['guard'] ?? null) ? $spec['guard'] : Auth::getDefaultDriver();
        $previousLocale = app()->getLocale();
        $previousDefault = Auth::getDefaultDriver();
        $previousPanel = Filament::getCurrentPanel();
        $previousTenant = self::tenant();
        $driver = Auth::guard($guardName);
        $previousUser = $driver->hasUser() ? $driver->user() : null;

        try {
            return $callback(self::actAs($spec, $guardName));
        } finally {
            if (self::$beforeRan) {
                self::after($spec);
            }

            app()->setLocale($previousLocale);
            Auth::shouldUse($previousDefault);
            Filament::setCurrentPanel($previousPanel);
            self::restoreTenant($previousTenant);

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
            self::restoreTenant(self::resolveTenant($spec));
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
            self::$beforeRan = true;
            $before($user, $panel);
        }

        return $user;
    }

    /**
     * `queue.after`: the undo of `queue.before` (whatever it set up in the worker), run even when the job failed.
     *
     * @param array<string, mixed> $spec
     */
    private static function after(array $spec): void
    {
        $after = config('filament-xlsx-import.queue.after');
        $id = $spec['user'] ?? null;

        if (!is_callable($after) || $id === null) {
            return;
        }

        $guard = is_string($spec['guard'] ?? null) ? $spec['guard'] : Auth::getDefaultDriver();
        $driver = Auth::guard($guard);
        $provider = method_exists($driver, 'getProvider') ? $driver->getProvider() : null;
        $user = $provider?->retrieveById($id);
        $panelId = $spec['panel'] ?? null;

        try {
            $panel = is_string($panelId) ? Filament::getPanel($panelId) : null;
        } catch (Throwable) {
            $panel = null;
        }

        if ($user instanceof Authenticatable) {
            $after($user, $panel);
        }
    }

    private static function tenant(): ?Model
    {
        try {
            return Filament::hasTenancy() ? Filament::getTenant() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function restoreTenant(?Model $tenant): void
    {
        try {
            if (Filament::hasTenancy()) {
                Filament::setTenant($tenant, true);
            }
        } catch (Throwable) {
            // The panel has no tenancy (any more): nothing to restore.
        }
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function resolveTenant(array $spec): ?Model
    {
        $tenant = $spec['tenant'] ?? null;

        if (!is_array($tenant) || !is_string($tenant['class'] ?? null) || !is_subclass_of($tenant['class'], Model::class)) {
            return null;
        }

        $found = $tenant['class']::query()->find($tenant['key'] ?? null);

        return $found instanceof Model ? $found : null;
    }
}
