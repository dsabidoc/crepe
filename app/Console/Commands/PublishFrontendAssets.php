<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class PublishFrontendAssets extends Command
{
    protected $signature = 'assets:publish {source : Directorio del build compilado} {--target= : Directorio build público que sirve el sitio}';

    protected $description = 'Publicar assets antes del manifiesto, conservando las versiones usadas por pantallas abiertas';

    public function handle(): int
    {
        $lock = null;

        try {
            $source = realpath((string) $this->argument('source'));
            if ($source === false || ! is_dir($source)) {
                throw new RuntimeException('No existe el directorio del build compilado.');
            }
            $contents = File::get($source.'/manifest.json');
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            $files = $this->validateBuild($source, $manifest);
            $target = rtrim((string) ($this->option('target') ?: public_path('build')), '/');
            File::ensureDirectoryExists($target);
            $lock = fopen($target.'/.publish.lock', 'c');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Hay otra publicación de assets en curso.');
            }

            foreach ($files as $path => $hash) {
                $destination = $target.'/'.$path;
                if (! is_file($destination) || hash_file('sha256', $destination) !== $hash) {
                    $this->replaceFile($destination, File::get($source.'/'.$path));
                }
            }
            foreach ($files as $path => $hash) {
                if (! is_file($target.'/'.$path) || hash_file('sha256', $target.'/'.$path) !== $hash) {
                    throw new RuntimeException('No se pudo verificar el asset publicado: '.$path);
                }
            }
            if (hash_file('sha256', $source.'/manifest.json') !== hash('sha256', $contents)) {
                throw new RuntimeException('El build cambió durante la publicación. El manifiesto anterior sigue activo.');
            }
            if (is_file($source.'/fonts-manifest.json')) {
                $fonts = File::get($source.'/fonts-manifest.json');
                json_decode($fonts, true, flags: JSON_THROW_ON_ERROR);
                $this->replaceFile($target.'/fonts-manifest.json', $fonts);
            }
            $this->replaceFile($target.'/manifest.json', $contents);
            $this->info(count($files).' assets verificados. Manifiesto activado; versiones anteriores conservadas.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function validateBuild(string $source, mixed $manifest): array
    {
        if (! is_array($manifest) || $manifest === []) {
            throw new RuntimeException('El manifiesto del build no es válido.');
        }
        $files = [];
        foreach ($manifest as $entry) {
            if (! is_array($entry) || ! is_string($entry['file'] ?? null)) {
                throw new RuntimeException('El manifiesto contiene una entrada inválida.');
            }
            foreach (['imports', 'dynamicImports'] as $group) {
                if (! is_array($entry[$group] ?? [])) {
                    throw new RuntimeException('El manifiesto contiene imports inválidos.');
                }
                foreach ($entry[$group] ?? [] as $import) {
                    if (! is_string($import) || ! array_key_exists($import, $manifest)) {
                        throw new RuntimeException('Falta una entrada importada en el manifiesto.');
                    }
                }
            }
            $paths = [$entry['file']];
            foreach (['css', 'assets'] as $group) {
                if (! is_array($entry[$group] ?? [])) {
                    throw new RuntimeException('El manifiesto contiene referencias inválidas.');
                }
                $paths = [...$paths, ...($entry[$group] ?? [])];
            }
            foreach ($paths as $path) {
                if (! is_string($path) || ! preg_match('/\A[A-Za-z0-9_.\/-]+\z/', $path) || str_starts_with($path, '/') || array_intersect(explode('/', $path), ['.', '..'])) {
                    throw new RuntimeException('El manifiesto contiene una ruta de asset inválida.');
                }
                $resolved = realpath($source.'/'.$path);
                if ($resolved === false || ! str_starts_with($resolved, $source.'/') || ! is_file($resolved)) {
                    throw new RuntimeException('Falta un asset del build: '.$path);
                }
                $files[$path] = hash_file('sha256', $resolved);
            }
        }

        return $files;
    }

    private function replaceFile(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($path));
        $temporary = tempnam(dirname($path), '.asset-publish-');
        if ($temporary === false) {
            throw new RuntimeException('No se pudo preparar la publicación de '.$path);
        }
        try {
            if (file_put_contents($temporary, $contents) !== strlen($contents) || ! chmod($temporary, 0644) || ! rename($temporary, $path)) {
                throw new RuntimeException('No se pudo publicar '.$path);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
