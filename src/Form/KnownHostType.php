<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class KnownHostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('host', TextType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Regex('/^[A-Za-z0-9.-]+$/', 'Host name only, without user or path.')],
                'attr' => ['placeholder' => 'git.example.com'],
            ])
            ->add('port', IntegerType::class, [
                'data' => 22,
                'constraints' => [new Assert\Range(min: 1, max: 65535)],
            ]);
    }
}
