<?php

declare(strict_types=1);

namespace App\Satis;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form model for the general and archive settings of satis.json.
 */
final class ConfigurationData
{
    public const STABILITIES = ['dev' => 'dev', 'alpha' => 'alpha', 'beta' => 'beta', 'RC' => 'RC', 'stable' => 'stable'];
    public const ARCHIVE_FORMATS = ['zip' => 'zip', 'tar' => 'tar'];

    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    public ?string $name = '';

    #[Assert\NotBlank]
    #[Assert\Url(requireTld: false)]
    public ?string $homepage = '';

    #[Assert\Length(max: 500)]
    public ?string $description = null;

    public bool $requireAll = true;
    public bool $requireDependencies = false;
    public bool $requireDevDependencies = false;

    #[Assert\Choice(choices: ['dev', 'alpha', 'beta', 'RC', 'stable'])]
    public ?string $minimumStability = 'dev';

    public bool $outputHtml = true;
    public bool $providers = false;

    public bool $archiveEnabled = false;

    #[Assert\Length(max: 200)]
    public ?string $archiveDirectory = 'dist';

    #[Assert\Choice(choices: ['zip', 'tar'])]
    public ?string $archiveFormat = 'zip';

    public bool $archiveSkipDev = false;

    #[Assert\Url(requireTld: false)]
    public ?string $archivePrefixUrl = null;

    #[Assert\Length(max: 500)]
    public ?string $archiveAbsoluteDirectory = null;

    /** One package name per line. */
    public ?string $archiveWhitelist = '';

    /** One package name per line. */
    public ?string $archiveBlacklist = '';

    public bool $archiveChecksum = true;
    public bool $archiveIgnoreFilters = false;
    public bool $archiveOverrideDistType = false;
    public bool $archiveRearchive = true;

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $data = new self();
        $data->name = (string) ($config['name'] ?? '');
        $data->homepage = (string) ($config['homepage'] ?? '');
        $data->description = isset($config['description']) ? (string) $config['description'] : null;
        $data->requireAll = (bool) ($config['require-all'] ?? false);
        $data->requireDependencies = (bool) ($config['require-dependencies'] ?? false);
        $data->requireDevDependencies = (bool) ($config['require-dev-dependencies'] ?? false);
        $stability = (string) ($config['minimum-stability'] ?? 'dev');
        $data->minimumStability = 'rc' === $stability ? 'RC' : $stability;
        $data->outputHtml = (bool) ($config['output-html'] ?? true);
        $data->providers = (bool) ($config['providers'] ?? false);

        $archive = $config['archive'] ?? null;
        if (is_array($archive)) {
            $data->archiveEnabled = true;
            $data->archiveDirectory = (string) ($archive['directory'] ?? 'dist');
            $data->archiveFormat = (string) ($archive['format'] ?? 'zip');
            $data->archiveSkipDev = (bool) ($archive['skip-dev'] ?? false);
            $data->archivePrefixUrl = isset($archive['prefix-url']) ? (string) $archive['prefix-url'] : null;
            $data->archiveAbsoluteDirectory = isset($archive['absolute-directory']) ? (string) $archive['absolute-directory'] : null;
            $data->archiveWhitelist = implode("\n", (array) ($archive['whitelist'] ?? []));
            $data->archiveBlacklist = implode("\n", (array) ($archive['blacklist'] ?? []));
            $data->archiveChecksum = (bool) ($archive['checksum'] ?? true);
            $data->archiveIgnoreFilters = (bool) ($archive['ignore-filters'] ?? false);
            $data->archiveOverrideDistType = (bool) ($archive['override-dist-type'] ?? false);
            $data->archiveRearchive = (bool) ($archive['rearchive'] ?? true);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function applyTo(array $config): array
    {
        $config['name'] = trim((string) $this->name);
        $config['homepage'] = rtrim(trim((string) $this->homepage), '/');
        $config['description'] = null !== $this->description && '' !== trim($this->description) ? trim($this->description) : null;
        $config['require-all'] = $this->requireAll;
        $config['require-dependencies'] = $this->requireDependencies;
        $config['require-dev-dependencies'] = $this->requireDevDependencies;
        $config['minimum-stability'] = $this->minimumStability ?? 'dev';
        $config['output-html'] = $this->outputHtml;
        $config['providers'] = $this->providers;

        if (!$this->archiveEnabled) {
            unset($config['archive']);

            return $config;
        }

        $archive = is_array($config['archive'] ?? null) ? $config['archive'] : [];
        $archive['directory'] = '' !== trim((string) $this->archiveDirectory) ? trim((string) $this->archiveDirectory) : 'dist';
        $archive['format'] = $this->archiveFormat ?? 'zip';
        $archive['skip-dev'] = $this->archiveSkipDev;
        $archive['prefix-url'] = null !== $this->archivePrefixUrl && '' !== trim($this->archivePrefixUrl) ? trim($this->archivePrefixUrl) : null;
        $archive['absolute-directory'] = null !== $this->archiveAbsoluteDirectory && '' !== trim($this->archiveAbsoluteDirectory) ? trim($this->archiveAbsoluteDirectory) : null;
        $archive['whitelist'] = self::lines((string) $this->archiveWhitelist);
        $archive['blacklist'] = self::lines((string) $this->archiveBlacklist);
        $archive['checksum'] = $this->archiveChecksum;
        $archive['ignore-filters'] = $this->archiveIgnoreFilters;
        $archive['override-dist-type'] = $this->archiveOverrideDistType;
        $archive['rearchive'] = $this->archiveRearchive;
        $config['archive'] = $archive;

        return $config;
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $lines = preg_split('/[\r\n,]+/', $text) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $l): bool => '' !== $l));

        return array_values(array_unique($lines));
    }
}
