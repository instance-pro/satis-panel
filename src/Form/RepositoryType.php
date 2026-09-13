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
                'help' => 'Only needed for repositories that do not provide a composer.json name.',
            ])
            ->add('noApi', CheckboxType::class, [
                'required' => false,
                'label' => 'Do not use the provider API (no-api), clone via git instead',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RepositoryData::class]);
    }
}
