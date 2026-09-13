<?php

declare(strict_types=1);

namespace App\Form;

use App\Auth\ComposerAuthManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class ComposerAuthType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach (ComposerAuthManager::TYPES as $type => $info) {
            $choices[$info['label']] = $type;
        }
        $builder
            ->add('type', ChoiceType::class, ['choices' => $choices, 'attr' => ['data-auth-type' => '']])
            ->add('host', TextType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Regex('/^[A-Za-z0-9.-]+(:\d+)?$/', 'Host name only, e.g. github.com')],
                'attr' => ['placeholder' => 'github.com', 'autocomplete' => 'off'],
            ])
            ->add('username', TextType::class, [
                'required' => false,
                'label' => 'Username / consumer key',
                'attr' => ['autocomplete' => 'off', 'data-auth-field' => 'username'],
            ])
            ->add('secret', TextType::class, [
                'label' => 'Token / password / consumer secret',
                'constraints' => [new Assert\NotBlank()],
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'class' => 'font-mono'],
            ]);
    }
}
