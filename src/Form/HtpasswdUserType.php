<?php

declare(strict_types=1);

namespace App\Form;

use App\Auth\HtpasswdManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class HtpasswdUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Regex(HtpasswdManager::USERNAME_PATTERN, 'Only letters, digits and . _ @ + - are allowed.')],
                'attr' => ['autocomplete' => 'off'],
            ])
            ->add('password', TextType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 8, max: 200)],
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'class' => 'font-mono'],
                'help' => 'At least 8 characters. Saving an existing user name replaces its password.',
            ]);
    }
}
