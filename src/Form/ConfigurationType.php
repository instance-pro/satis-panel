<?php

declare(strict_types=1);

namespace App\Form;

use App\Satis\ConfigurationData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['help' => 'Repository name, e.g. acme/satis'])
            ->add('homepage', UrlType::class, ['default_protocol' => 'https', 'help' => 'Public URL of this Satis instance, e.g. https://satis.example.com'])
            ->add('description', TextType::class, ['required' => false])
            ->add('requireAll', CheckboxType::class, ['required' => false, 'label' => 'require-all: build every package found in the repositories'])
            ->add('requireDependencies', CheckboxType::class, ['required' => false, 'label' => 'require-dependencies: also mirror dependencies of the selected packages'])
            ->add('requireDevDependencies', CheckboxType::class, ['required' => false, 'label' => 'require-dev-dependencies: also mirror dev dependencies'])
            ->add('minimumStability', ChoiceType::class, ['choices' => ConfigurationData::STABILITIES, 'label' => 'minimum-stability'])
            ->add('outputHtml', CheckboxType::class, ['required' => false, 'label' => 'output-html: generate the index.html package overview'])
            ->add('providers', CheckboxType::class, ['required' => false, 'label' => 'providers: generate per-package provider files (Composer 1 style, larger output)'])
            ->add('archiveEnabled', CheckboxType::class, ['required' => false, 'label' => 'Enable archives: mirror package dist files so Composer clients do not need access to the VCS'])
            ->add('archiveDirectory', TextType::class, ['required' => false, 'label' => 'directory', 'help' => 'Relative to the output directory, default "dist".'])
            ->add('archiveFormat', ChoiceType::class, ['choices' => ConfigurationData::ARCHIVE_FORMATS, 'label' => 'format'])
            ->add('archiveSkipDev', CheckboxType::class, ['required' => false, 'label' => 'skip-dev: do not create archives for branches'])
            ->add('archivePrefixUrl', UrlType::class, ['required' => false, 'default_protocol' => 'https', 'label' => 'prefix-url', 'help' => 'Public URL of the archives, defaults to the homepage.'])
            ->add('archiveAbsoluteDirectory', TextType::class, ['required' => false, 'label' => 'absolute-directory', 'help' => 'Absolute path of the dist files, only needed when they are not below the output directory.'])
            ->add('archiveWhitelist', TextareaType::class, ['required' => false, 'label' => 'whitelist', 'help' => 'One package name per line. Only these packages get archives.', 'attr' => ['rows' => 3]])
            ->add('archiveBlacklist', TextareaType::class, ['required' => false, 'label' => 'blacklist', 'help' => 'One package name per line. These packages get no archives.', 'attr' => ['rows' => 3]])
            ->add('archiveChecksum', CheckboxType::class, ['required' => false, 'label' => 'checksum: provide the sha1 checksum of the dist files'])
            ->add('archiveIgnoreFilters', CheckboxType::class, ['required' => false, 'label' => 'ignore-filters: ignore .gitattributes export-ignore filters'])
            ->add('archiveOverrideDistType', CheckboxType::class, ['required' => false, 'label' => 'override-dist-type: use the archive format as dist type in the file name'])
            ->add('archiveRearchive', CheckboxType::class, ['required' => false, 'label' => 'rearchive: create new archives for packages that already provide a tar/zip dist']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ConfigurationData::class]);
    }
}
