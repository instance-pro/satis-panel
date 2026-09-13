<?php

declare(strict_types=1);

namespace App\Form;

use App\Auth\IpAllowList;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class IpAllowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ip', TextType::class, [
                'label' => 'IP address or range',
                'attr' => ['placeholder' => '203.0.113.10 or 10.0.0.0/8', 'autocomplete' => 'off', 'class' => 'font-mono'],
                'constraints' => [new Assert\NotBlank(), new Assert\Callback(static function (mixed $value, ExecutionContextInterface $context): void {
                    if (!is_string($value) || '' === trim($value)) {
                        return;
                    }
                    try {
                        IpAllowList::normalize($value);
                    } catch (\InvalidArgumentException $e) {
                        $context->buildViolation($e->getMessage())->addViolation();
                    }
                })],
                'help' => 'IPv4 or IPv6, optionally with a CIDR prefix.',
            ])
            ->add('label', TextType::class, [
                'label' => 'Label (optional)',
                'required' => false,
                'attr' => ['placeholder' => 'Office, CI runner …', 'autocomplete' => 'off'],
                'constraints' => [new Assert\Regex(IpAllowList::LABEL_PATTERN, 'Letters, digits, spaces and . _ @ + - only.')],
            ]);
    }
}
