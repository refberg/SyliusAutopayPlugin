<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Form\Type;

use Refberg\SyliusAutopayPlugin\AutopayGateway;
use Refberg\SyliusAutopayPlugin\Config\Environment;
use Refberg\SyliusAutopayPlugin\Config\HashAlgorithm;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use function array_combine;
use function is_array;
use function is_string;
use function trim;

/**
 * Gateway configuration of the Autopay payment method.
 *
 * The shared key is never rendered back to the browser. Leaving it empty while editing
 * keeps the stored value. The whole configuration is encrypted at rest by Sylius.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class AutopayGatewayConfigurationType extends AbstractType
{
    private const SECRET_FIELDS = [AutopayGateway::CONFIG_SHARED_KEY];

    private const GROUPS = ['sylius', AutopayGateway::FACTORY_NAME];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(AutopayGateway::CONFIG_ENVIRONMENT, ChoiceType::class, [
                'label' => 'refberg_autopay.form.gateway_configuration.environment',
                'choices' => [
                    'refberg_autopay.form.gateway_configuration.environment_sandbox' => Environment::Sandbox->value,
                    'refberg_autopay.form.gateway_configuration.environment_production' => Environment::Production->value,
                ],
                'empty_data' => Environment::Sandbox->value,
                'constraints' => [new NotBlank(groups: self::GROUPS)],
            ])
            ->add(AutopayGateway::CONFIG_SERVICE_ID, TextType::class, [
                'label' => 'refberg_autopay.form.gateway_configuration.service_id',
                'constraints' => [
                    new NotBlank(groups: self::GROUPS),
                    new Regex(pattern: '/^\d+$/', message: 'refberg_autopay.service_id.invalid', groups: self::GROUPS),
                ],
            ])
            ->add(AutopayGateway::CONFIG_SHARED_KEY, PasswordType::class, [
                'label' => 'refberg_autopay.form.gateway_configuration.shared_key',
                'help' => 'refberg_autopay.form.gateway_configuration.secret_help',
                'always_empty' => true,
                'constraints' => [new NotBlank(groups: self::GROUPS)],
            ])
            ->add(AutopayGateway::CONFIG_HASH_ALGORITHM, ChoiceType::class, [
                'label' => 'refberg_autopay.form.gateway_configuration.hash_algorithm',
                'choices' => [
                    'SHA-256' => HashAlgorithm::Sha256->value,
                    'SHA-512' => HashAlgorithm::Sha512->value,
                ],
                'empty_data' => HashAlgorithm::Sha256->value,
                'constraints' => [new NotBlank(groups: self::GROUPS)],
            ])
            ->add(AutopayGateway::CONFIG_CURRENCY, ChoiceType::class, [
                'label' => 'refberg_autopay.form.gateway_configuration.currency',
                'help' => 'refberg_autopay.form.gateway_configuration.currency_help',
                'choices' => array_combine(AutopayGateway::CURRENCIES, AutopayGateway::CURRENCIES),
                'empty_data' => AutopayGateway::DEFAULT_CURRENCY,
                'constraints' => [new NotBlank(groups: self::GROUPS)],
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, self::keepStoredSecrets(...))
        ;
    }

    private static function keepStoredSecrets(FormEvent $event): void
    {
        $submitted = $event->getData();
        $stored = $event->getForm()->getData();
        if (!is_array($submitted) || !is_array($stored)) {
            return;
        }

        foreach (self::SECRET_FIELDS as $field) {
            $value = $submitted[$field] ?? '';
            if ((!is_string($value) || '' === trim($value)) && is_string($stored[$field] ?? null) && '' !== $stored[$field]) {
                $submitted[$field] = $stored[$field];
            }
        }

        $event->setData($submitted);
    }
}
