<?php

declare(strict_types=1);

namespace App\Form;

use App\Satis\RepositoryData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class RepositoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'choices' => RepositoryData::TYPES,
            ])
            ->add('url', TextType::class, [
                'label' => 'URL',
                'attr' => ['placeholder' => 'git@bitbucket.org:workspace/repository.git'],
                'help' => 'SSH URLs use the SSH key from the SSH page, HTTPS URLs use the Composer authentication (COMPOSER_AUTH / auth.json).',
            ])
            ->add('name', TextType::class, [
                'required' => false,
                'label' => 'Name (optional)',
                'help' => 'Identifier of this repository for Composer and Satis (used in messages and for composer config repositories.<name>). Has no effect on the package names, which come from composer.json.',
            ])
            ->add('noApi', CheckboxType::class, [
                'required' => false,
                'label' => 'Do not use the provider API (no-api), clone via git instead',
            ])
            ->add('webhookSecret', TextType::class, [
                'required' => false,
                'label' => 'Webhook secret (optional)',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'class' => 'font-mono', 'data-secret-input' => ''],
                'help' => 'Enter the same value as webhook secret at Bitbucket, GitHub, Gitea or GitLab. Requests for this repository are then only accepted with a valid signature.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RepositoryData::class]);
    }
}
