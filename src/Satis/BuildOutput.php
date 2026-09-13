<?php

declare(strict_types=1);

namespace App\Satis;

/**
 * Reads the packages of the current build output and maps them to the
 * repository they come from (via the source/dist URL satis writes per version).
 */
final class BuildOutput
{
    public function __construct(private readonly SatisConfig $config)
    {
    }

    public function exists(): bool
    {
        return is_file($this->config->outputDir().'/packages.json');
    }

    /**
     * @return array<string, list<string>> normalized source url => package names
     */
    public function packagesBySource(): array
    {
        $dir = $this->config->outputDir();
        $files = [];
        $packagesJson = $this->readJson($dir.'/packages.json');
        foreach (array_keys((array) ($packagesJson['includes'] ?? [])) as $include) {
            $files[] = $dir.'/'.$include;
        }
        if ([] === $files) {
            $files = glob($dir.'/p2/*/*.json') ?: [];
        }

        $result = [];
        foreach ($files as $file) {
            $data = $this->readJson($file);
            foreach ((array) ($data['packages'] ?? []) as $name => $versions) {
                foreach ((array) $versions as $version) {
                    if (!is_array($version)) {
                        continue;
                    }
                    $url = $version['source']['url'] ?? $version['dist']['url'] ?? null;
                    if (!is_string($url) || '' === $url) {
                        continue;
                    }
                    $result[RepositoryUrlMatcher::normalize($url)][(string) $name] = true;
                }
            }
        }

        return array_map(static fn (array $names): array => array_keys($names), $result);
    }

    /**
     * @param list<array<string, mixed>> $repositories satis.json repositories
     *
     * @return list<list<string>> package names per repository, same order as the input
     */
    public function packagesForRepositories(array $repositories): array
    {
        $bySource = $this->packagesBySource();
        $result = [];
        foreach ($repositories as $repository) {
            $url = $repository['url'] ?? null;
            $packages = is_string($url) && '' !== $url ? ($bySource[RepositoryUrlMatcher::normalize($url)] ?? []) : [];
            sort($packages);
            $result[] = $packages;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }
}
