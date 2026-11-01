<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Console;

use Illuminate\Console\Command;
use MyBeast\Errors\Laravel\Capture\Release;
use MyBeast\Errors\Laravel\Errors;
use MyBeast\Errors\Laravel\Transport\ServiceClient;
use MyBeast\Errors\Laravel\Transport\TransportFailed;
use Symfony\Component\Finder\Finder;

/**
 * A deploy's step: the build's source maps (and fonts) go up first, then "this release is live here" - which sends
 * the "it's fixed" notices waiting for a newer release. Run it once the new release serves requests.
 */
final class ReleaseCommand extends Command
{
    protected $signature = 'mybeast:errors-release
        {--release= : The release (default: config mybeast-errors.release, else the git commit)}
        {--environment= : Default: config mybeast-errors.environment}
        {--sourcemaps= : Folder of *.js.map files (default: config mybeast-errors.sourcemaps_path)}
        {--files-only : Upload the files, do not say the release is live}';

    protected $description = 'Upload the build\'s source maps to errors.my-beast.com and mark the release as live';

    public function handle(Errors $errors): int
    {
        $config = (array) config('mybeast-errors');

        if (! $errors->capturing()) {
            $this->error('My Beast errors is off: set MYBEAST_ERRORS_URL and MYBEAST_ERRORS_SECRET.');

            return self::FAILURE;
        }

        $release = (string) ($this->option('release') ?: Release::current($config['release'] ?? null, base_path()));
        $environment = (string) ($this->option('environment') ?: $errors->environment());

        if ($release === '') {
            $this->error('No release: pass --release or set MYBEAST_ERRORS_RELEASE.');

            return self::FAILURE;
        }

        $client = new ServiceClient(rtrim((string) $config['url'], '/'), (string) $config['secret']);

        try {
            $maps = $this->upload($client, $release, 'sourcemap', $this->files((string) ($this->option('sourcemaps') ?: $config['sourcemaps_path']), ['*.js.map']));
            $fonts = $this->upload($client, $release, 'font', $this->globbed((array) ($config['fonts'] ?? [])));
            $this->info("Release {$release}: {$maps} source map(s) and {$fonts} font(s) uploaded.");

            if (! $this->option('files-only')) {
                $live = $client->deployed($release, $environment);
                $this->info("Live in {$environment}; ".(int) ($live['notified'] ?? 0).' person(s) to be told an issue is fixed.');
            }
        } catch (TransportFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param  list<string>  $paths */
    private function upload(ServiceClient $client, string $release, string $kind, array $paths): int
    {
        foreach ($paths as $path) {
            $client->putReleaseFile($release, $kind, basename($path), (string) file_get_contents($path));
        }

        return count($paths);
    }

    /**
     * @param  list<string>  $patterns
     * @return list<string>
     */
    private function files(string $directory, array $patterns): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (Finder::create()->files()->in($directory)->name($patterns) as $file) {
            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    /**
     * @param  list<string>  $globs
     * @return list<string>
     */
    private function globbed(array $globs): array
    {
        $files = [];

        foreach ($globs as $glob) {
            foreach (glob($glob) ?: [] as $file) {
                if (is_file($file)) {
                    $files[] = $file;
                }
            }
        }

        return array_values(array_unique($files));
    }
}
