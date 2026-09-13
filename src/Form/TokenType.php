<?php

declare(strict_types=1);

namespace App\Form;

use App\Auth\TokenManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class TokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Token name',
            'attr' => ['placeholder' => 'CI pipeline', 'autocomplete' => 'off'],
            'constraints' => [new Assert\NotBlank(), new Assert\Regex(TokenManager::NAME_PATTERN, 'Letters, digits, spaces and . _ @ + - only.')],
            'help' => 'Only a label so you know where the token is used.',
        ]);
    }
}
