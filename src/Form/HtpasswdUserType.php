<?php

declare(strict_types=1);

namespace App\Form;

use App\Auth\HtpasswdManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
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
            ->add('password', PasswordType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 8, max: 200)],
                'attr' => ['autocomplete' => 'new-password'],
                'help' => 'Stored as bcrypt hash. Saving an existing user name replaces its password.',
            ]);
    }
}
