<?php

declare(strict_types=1);

namespace App\Satis;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form model for one entry of the satis.json "repositories" list.
 */
final class RepositoryData
{
    public const TYPES = [
        'VCS (auto-detect git/hg/svn)' => 'vcs',
        'Git' => 'git',
        'GitHub' => 'github',
        'GitLab' => 'gitlab',
        'Bitbucket' => 'git-bitbucket',
        'Composer repository' => 'composer',
        'Local path' => 'path',
        'Mercurial' => 'hg',
        'Subversion' => 'svn',
    ];

    #[Assert\NotBlank]
    #[Assert\Choice(callback: 'typeValues')]
    public ?string $type = 'vcs';

    #[Assert\NotBlank]
    #[Assert\Length(max: 500)]
    public ?string $url = '';

    #[Assert\Length(max: 200)]
    public ?string $name = null;

    /** Composer repositories only: skip API and use the raw git URL for VCS repositories. */
    public bool $noApi = false;

    /**
     * @return list<string>
     */
    public static function typeValues(): array
    {
        return array_values(self::TYPES);
    }

    /**
     * @param array<string, mixed> $repository
     */
    public static function fromArray(array $repository): self
    {
        $data = new self();
        $data->type = (string) ($repository['type'] ?? 'vcs');
        $data->url = (string) ($repository['url'] ?? '');
        $data->name = isset($repository['name']) ? (string) $repository['name'] : null;
        $data->noApi = (bool) ($repository['no-api'] ?? false);

        return $data;
    }

    /**
     * Applies the form values on top of an existing entry so that keys the
     * form does not know (options, filter, ...) survive.
     *
     * @param array<string, mixed> $repository
     *
     * @return array<string, mixed>
     */
    public function applyTo(array $repository = []): array
    {
        $repository['type'] = $this->type ?? 'vcs';
        $repository['url'] = trim((string) $this->url);
        if (null !== $this->name && '' !== trim($this->name)) {
            $repository['name'] = trim($this->name);
        } else {
            unset($repository['name']);
        }
        if ($this->noApi) {
            $repository['no-api'] = true;
        } else {
            unset($repository['no-api']);
        }

        return $repository;
    }
}
