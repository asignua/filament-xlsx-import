<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Each import run is a directory `<directory>/<ulid>/` on a local-driver disk: the uploaded file
 * (`source.<ext>`), `meta.json` (owner, panel, progress, the dry-run summary) and, when rows failed,
 * `report.xlsx`. No table, so the prune command works on the disk alone.
 */
final class RunStore
{
    public const string ROUTE = 'filament-xlsx-import.report';

    public const string ID_PATTERN = '[0-9a-z]{26}';

    public static function disk(): Filesystem
    {
        $name = config('filament-xlsx-import.storage.disk');

        return Storage::disk(is_string($name) && $name !== '' ? $name : 'local');
    }

    public static function directory(): string
    {
        $dir = config('filament-xlsx-import.storage.directory');

        return trim(is_string($dir) && $dir !== '' ? $dir : 'filament-xlsx-imports', '/');
    }

    public static function retentionHours(): int
    {
        return max(0, (int) config('filament-xlsx-import.storage.retention_hours', 24));
    }

    public static function linkHours(): int
    {
        return self::retentionHours() ?: 24 * 30;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function create(array $meta): string
    {
        $id = strtolower((string) Str::ulid());
        self::disk()->makeDirectory(self::directory().'/'.$id);
        self::put($id, $meta + ['created_at' => time()]);

        return $id;
    }

    public static function exists(string $id): bool
    {
        return (bool) preg_match('/^'.self::ID_PATTERN.'$/', $id) && self::disk()->exists(self::directory().'/'.$id.'/meta.json');
    }

    /**
     * @return array<string, mixed>
     */
    public static function meta(string $id): array
    {
        if (!preg_match('/^'.self::ID_PATTERN.'$/', $id)) {
            return [];
        }

        $raw = self::disk()->exists(self::directory().'/'.$id.'/meta.json') ? self::disk()->get(self::directory().'/'.$id.'/meta.json') : null;
        $meta = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($meta) ? $meta : [];
    }

    /**
     * @param array<string, mixed> $merge
     */
    public static function put(string $id, array $merge): void
    {
        $meta = array_replace(self::meta($id), $merge);

        self::disk()->put(self::directory().'/'.$id.'/meta.json', (string) json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * The absolute path of a file of the run.
     */
    public static function path(string $id, string $file): string
    {
        if (!preg_match('/^'.self::ID_PATTERN.'$/', $id) || str_contains($file, '/') || str_contains($file, '..')) {
            throw new RuntimeException('Bad import run.');
        }

        $path = self::disk()->path(self::directory().'/'.$id.'/'.$file);

        return $path;
    }

    public static function source(string $id): SourceFile
    {
        $meta = self::meta($id);
        $extension = is_string($meta['extension'] ?? null) ? $meta['extension'] : 'xlsx';

        return new SourceFile(self::path($id, 'source.'.$extension), $extension, is_string($meta['delimiter'] ?? null) ? $meta['delimiter'] : ',');
    }

    public static function forget(string $id): void
    {
        if (preg_match('/^'.self::ID_PATTERN.'$/', $id)) {
            self::disk()->deleteDirectory(self::directory().'/'.$id);
        }
    }

    public static function dropSource(string $id): void
    {
        $meta = self::meta($id);
        $extension = is_string($meta['extension'] ?? null) ? $meta['extension'] : 'xlsx';
        self::disk()->delete(self::directory().'/'.$id.'/source.'.$extension);
    }

    public static function url(string $id, ?string $root = null): string
    {
        $generator = app('url');
        // Put back what was there (a worker or a test may have forced its own root): never null it out.
        [$previousRoot, $previousScheme] = (fn (): array => [$this->forcedRoot, $this->forceScheme])->call($generator);

        if ($root !== null && $root !== '') {
            self::useOrigin($root);
            $generator->forceScheme(parse_url($root, PHP_URL_SCHEME) ?: null);
        }

        try {
            return URL::temporarySignedRoute(self::ROUTE, now()->addHours(self::linkHours()), ['id' => $id]);
        } finally {
            if ($root !== null && $root !== '') {
                self::useOrigin($previousRoot);
                $generator->forceScheme(is_string($previousScheme) ? rtrim($previousScheme, ':/') : null);
            }
        }
    }

    /**
     * Laravel 13 renamed forceRootUrl() to useOrigin() and deprecated the old name.
     */
    private static function useOrigin(?string $root): void
    {
        $url = app(UrlGenerator::class);

        // Laravel 12: useOrigin() does not exist yet. Dynamic names: static analysis only sees one of the two versions.
        $method = version_compare(app()->version(), '13.0.0', '>=') ? 'useOrigin' : 'forceRootUrl';
        $url->{$method}($root);
    }

    /**
     * Whether the run was started by this user in this panel for this import target. A run id comes from the browser
     * (a hidden form field): it is never trusted to be the caller's own.
     */
    public static function ownedBy(string $id, int|string|null $user, ?string $guard, ?string $panel, string $target): bool
    {
        $meta = self::meta($id);

        return $meta !== []
            && $user !== null
            && (string) ($meta['user'] ?? '') === (string) $user
            && ($meta['guard'] ?? null) === $guard
            && ($meta['panel'] ?? null) === $panel
            && ($meta['target'] ?? null) === $target;
    }

    public static function temporaryFile(string $prefix = 'xlsx-import-'): string
    {
        $dir = storage_path('framework/cache/filament-xlsx-import');

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}.");
        }

        $path = tempnam($dir, $prefix);

        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }

        return $path;
    }
}
