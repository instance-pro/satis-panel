<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class SshImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('privateKey', TextareaType::class, [
            'label' => 'Private key',
            'constraints' => [new Assert\NotBlank()],
            'attr' => ['rows' => 8, 'placeholder' => "-----BEGIN OPENSSH PRIVATE KEY-----\n...", 'spellcheck' => 'false', 'class' => 'font-mono'],
            'help' => 'Unencrypted OpenSSH or PEM private key. It replaces the current key.',
        ]);
    }
}
