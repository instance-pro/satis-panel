<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class SshGenerateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'choices' => ['ed25519 (recommended)' => 'ed25519', 'RSA 4096' => 'rsa'],
                'label' => 'Key type',
            ])
            ->add('comment', TextType::class, [
                'required' => false,
                'label' => 'Comment',
                'attr' => ['placeholder' => 'satis-panel@example.com'],
                'constraints' => [new Assert\Length(max: 100), new Assert\Regex('/^[^\s"\'\\\\]*$/', 'No whitespace or quotes.')],
            ]);
    }
}
