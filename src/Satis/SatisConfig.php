<?php

declare(strict_types=1);

namespace App\Satis;

use App\Auth\HtpasswdManager;
use JsonSchema\Validator;

/**
 * Reads and writes satis.json. Every write is validated against the JSON
 * schema shipped with composer/satis, and output-dir is pinned to the
 * directory nginx serves.
 */
final class SatisConfig
{
    public function __construct(
        private readonly string $satisConfigFile,
        private readonly string $satisOutputDir,
        private readonly string $satisSchemaFile,
    ) {
    }

    public function path(): string
    {
        return $this->satisConfigFile;
    }

    public function outputDir(): string
    {
        return $this->satisOutputDir;
    }

    public function exists(): bool
    {
        return is_file($this->satisConfigFile);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'name' => 'satis-panel/repository',
            'homepage' => 'http://localhost',
            'output-dir' => $this->satisOutputDir,
            'repositories' => [],
            'require-all' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ConfigException when the file exists but is not valid JSON
     */
    public function load(): array
    {
        if (!$this->exists()) {
            return $this->defaults();
        }

        $json = file_get_contents($this->satisConfigFile);
        if (false === $json) {
            throw new ConfigException(sprintf('Cannot read %s.', $this->satisConfigFile));
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigException(sprintf('%s is not valid JSON: %s', $this->satisConfigFile, $e->getMessage()));
        }
        if (!is_array($data)) {
            throw new ConfigException(sprintf('%s does not contain a JSON object.', $this->satisConfigFile));
        }
        $data['repositories'] = array_values($data['repositories'] ?? []);

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function repositories(): array
    {
        return $this->load()['repositories'];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws ConfigException when the configuration violates the satis schema
     */
    public function save(array $data): void
    {
        $data['output-dir'] = $this->satisOutputDir;
        $data['repositories'] = array_values($data['repositories'] ?? []);
        $data = $this->clean($data);

        $errors = $this->validate($data);
        if ([] !== $errors) {
            throw new ConfigException('satis.json would be invalid: '.implode('; ', $errors), $errors);
        }

        $dir = dirname($this->satisConfigFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ConfigException(sprintf('Cannot create %s.', $dir));
        }

        $json = json_encode($this->toJsonValue($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        $tmp = $this->satisConfigFile.'.tmp';
        if (false === file_put_contents($tmp, $json, LOCK_EX)) {
            @unlink($tmp);
            throw new ConfigException(sprintf('Cannot write %s.', $this->satisConfigFile));
        }
        HtpasswdManager::keepOwnership($this->satisConfigFile, $tmp);
        if (!rename($tmp, $this->satisConfigFile)) {
            @unlink($tmp);
            throw new ConfigException(sprintf('Cannot write %s.', $this->satisConfigFile));
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    public function validate(array $data): array
    {
        $validator = new Validator();
        $value = $this->toJsonValue($data);
        $validator->validate($value, (object) ['$ref' => 'file://'.$this->satisSchemaFile]);
        if ($validator->isValid()) {
            return [];
        }

        $errors = [];
        foreach ($validator->getErrors() as $error) {
            $errors[] = ('' !== $error['property'] ? $error['property'].': ' : '').$error['message'];
        }

        return $errors;
    }

    /**
     * Drops empty optional structures, they would serialize as [] instead of {}.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        foreach (['require', 'archive', 'abandoned', 'blacklist', 'minimum-stability-per-package', 'config', 'repositories-dep', 'include-types', 'exclude-types', 'available-package-patterns'] as $key) {
            if (array_key_exists($key, $data) && (null === $data[$key] || [] === $data[$key])) {
                unset($data[$key]);
            }
        }
        if (isset($data['archive']) && is_array($data['archive'])) {
            foreach (['whitelist', 'blacklist'] as $key) {
                if (array_key_exists($key, $data['archive']) && [] === $data['archive'][$key]) {
                    unset($data['archive'][$key]);
                }
            }
            foreach ($data['archive'] as $key => $value) {
                if (null === $value || '' === $value) {
                    unset($data['archive'][$key]);
                }
            }
        }
        foreach ($data as $key => $value) {
            if (null === $value || '' === $value) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Converts the array into objects/lists so that JSON objects stay objects.
     */
    private function toJsonValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ([] === $value || array_is_list($value)) {
            return array_map($this->toJsonValue(...), $value);
        }
        $object = new \stdClass();
        foreach ($value as $key => $item) {
            $object->{$key} = $this->toJsonValue($item);
        }

        return $object;
    }
}
