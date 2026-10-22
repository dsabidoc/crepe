<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PublishFrontendAssetsTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_publish_activates_a_complete_build_and_keeps_assets_used_by_open_pages(): void
    {
        [$source, $target] = $this->buildDirectories();
        $manifest = [
            'resources/css/app.css' => ['file' => 'assets/new.css', 'isEntry' => true],
            'resources/js/app.js' => ['file' => 'assets/new.js', 'css' => ['assets/shared.css'], 'assets' => ['assets/font.woff2'], 'imports' => ['chunk']],
            'chunk' => ['file' => 'assets/chunk.js'],
        ];
        $this->write($source.'/manifest.json', json_encode($manifest));
        foreach (['new.css', 'new.js', 'shared.css', 'font.woff2', 'chunk.js'] as $file) {
            $this->write($source.'/assets/'.$file, 'complete '.$file);
        }
        $this->write($target.'/assets/old.css', 'style used by an open page');
        $this->write($target.'/manifest.json', '{"previous":true}');
        $this->write($source.'/fonts-manifest.json', '{"fonts":[]}');
        $originalPublicPath = public_path();
        app()->usePublicPath(dirname($target));

        try {
            $this->artisan('assets:publish', ['source' => $source])->assertSuccessful();

            $this->assertSame($manifest, json_decode(File::get($target.'/manifest.json'), true));
            $this->assertSame('style used by an open page', File::get($target.'/assets/old.css'));
            $this->assertSame('{"fonts":[]}', File::get($target.'/fonts-manifest.json'));
            foreach (['new.css', 'new.js', 'shared.css', 'font.woff2', 'chunk.js'] as $file) {
                $this->assertSame('complete '.$file, File::get($target.'/assets/'.$file));
            }
            $this->assertStringEndsWith('/build/assets/new.css', Vite::asset('resources/css/app.css'));
        } finally {
            app()->usePublicPath($originalPublicPath);
        }
    }

    #[TestWith(['assets/missing.css'])]
    #[TestWith(['../private.txt'])]
    #[TestWith(['/private.txt'])]
    public function test_an_incomplete_or_unsafe_build_leaves_the_active_manifest_and_assets_unchanged(string $asset): void
    {
        [$source, $target] = $this->buildDirectories();
        $this->write($source.'/manifest.json', json_encode(['resources/css/app.css' => ['file' => $asset]]));
        $this->write(dirname($source).'/private.txt', 'private');
        $this->write($target.'/manifest.json', '{"active":"old"}');
        $this->write($target.'/assets/old.css', 'old styles');

        $this->artisan('assets:publish', ['source' => $source, '--target' => $target])->assertFailed();

        $this->assertSame('{"active":"old"}', File::get($target.'/manifest.json'));
        $this->assertSame('old styles', File::get($target.'/assets/old.css'));
        $this->assertFileDoesNotExist($target.'/private.txt');
    }

    public function test_missing_import_dependencies_do_not_activate_a_new_manifest(): void
    {
        [$source, $target] = $this->buildDirectories();
        $this->write($source.'/manifest.json', json_encode(['resources/js/app.js' => ['file' => 'assets/new.js', 'dynamicImports' => ['missing-chunk']]]));
        $this->write($source.'/assets/new.js', 'new script');
        $this->write($target.'/manifest.json', '{"active":"old"}');

        $this->artisan('assets:publish', ['source' => $source, '--target' => $target])->assertFailed();

        $this->assertSame('{"active":"old"}', File::get($target.'/manifest.json'));
        $this->assertFileDoesNotExist($target.'/assets/new.js');
    }

    public function test_a_concurrent_publication_cannot_replace_the_active_manifest(): void
    {
        [$source, $target] = $this->buildDirectories();
        $this->write($source.'/manifest.json', json_encode(['resources/css/app.css' => ['file' => 'assets/new.css']]));
        $this->write($source.'/assets/new.css', 'new styles');
        $this->write($target.'/manifest.json', '{"active":"old"}');
        $lock = fopen($target.'/.publish.lock', 'c');
        flock($lock, LOCK_EX);

        try {
            $this->artisan('assets:publish', ['source' => $source, '--target' => $target])->assertFailed();

            $this->assertSame('{"active":"old"}', File::get($target.'/manifest.json'));
            $this->assertFileDoesNotExist($target.'/assets/new.css');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{string, string} */
    private function buildDirectories(): array
    {
        $directory = sys_get_temp_dir().'/crepe-assets-test-'.Str::uuid();
        $this->directories[] = $directory;
        File::ensureDirectoryExists($directory.'/source');
        File::ensureDirectoryExists($directory.'/public/build');

        return [$directory.'/source', $directory.'/public/build'];
    }

    private function write(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
    }
}
