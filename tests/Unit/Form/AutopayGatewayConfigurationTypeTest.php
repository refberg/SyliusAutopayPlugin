<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\Form;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Form\Type\AutopayGatewayConfigurationType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;
use function array_merge;

final class AutopayGatewayConfigurationTypeTest extends TestCase
{
    private const STORED = [
        'environment' => 'production',
        'service_id' => '100001',
        'shared_key' => 'stored-shared-key',
        'hash_algorithm' => 'sha256',
    ];

    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        $this->factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }

    public function test_shared_key_is_not_rendered(): void
    {
        $view = $this->factory->create(AutopayGatewayConfigurationType::class, self::STORED)->createView();

        self::assertSame('', $view['shared_key']->vars['value']);
        self::assertSame('100001', $view['service_id']->vars['value']);
    }

    public function test_empty_shared_key_keeps_the_stored_value(): void
    {
        $form = $this->factory->create(AutopayGatewayConfigurationType::class, self::STORED);

        $form->submit(['environment' => 'sandbox', 'service_id' => '200002', 'shared_key' => '   ', 'hash_algorithm' => 'sha512']);

        self::assertTrue($form->isSynchronized());
        self::assertSame([
            'environment' => 'sandbox',
            'service_id' => '200002',
            'shared_key' => 'stored-shared-key',
            'hash_algorithm' => 'sha512',
        ], $form->getData());
    }

    public function test_new_shared_key_replaces_the_stored_value(): void
    {
        $form = $this->factory->create(AutopayGatewayConfigurationType::class, self::STORED);

        $form->submit(array_merge(self::STORED, ['shared_key' => 'new-key']));

        self::assertSame('new-key', $form->getData()['shared_key'] ?? null);
    }

    public function test_defaults_for_a_new_method(): void
    {
        $form = $this->factory->create(AutopayGatewayConfigurationType::class);

        $form->submit(['service_id' => '1', 'shared_key' => 'k']);

        self::assertSame(['environment' => 'sandbox', 'service_id' => '1', 'shared_key' => 'k', 'hash_algorithm' => 'sha256'], $form->getData());
    }

    public function test_complete_configuration_is_valid_in_the_admin_validation_group(): void
    {
        $form = $this->adminForm(null);

        $form->submit(self::STORED);

        self::assertTrue($form->isValid());
    }

    public function test_empty_shared_key_is_valid_when_one_is_stored(): void
    {
        $form = $this->adminForm(self::STORED);

        $form->submit(array_merge(self::STORED, ['shared_key' => '']));

        self::assertTrue($form->isValid());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidFields(): iterable
    {
        yield 'empty service ID' => ['service_id', ''];
        yield 'non-numeric service ID' => ['service_id', '10000a'];
        yield 'empty shared key' => ['shared_key', ''];
        yield 'unknown hash algorithm' => ['hash_algorithm', 'md5'];
        yield 'unknown environment' => ['environment', 'staging'];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_field_is_rejected_in_the_admin_validation_group(string $field, string $value): void
    {
        $form = $this->adminForm(null);

        $form->submit(array_merge(self::STORED, [$field => $value]));

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get($field)->getErrors());
    }

    public function test_constraints_apply_only_to_the_sylius_validation_group(): void
    {
        $form = $this->factory->create(AutopayGatewayConfigurationType::class);

        $form->submit(array_merge(self::STORED, ['service_id' => '', 'shared_key' => '']));

        self::assertTrue($form->isValid());
    }

    /**
     * @param array<string, string>|null $data
     *
     * @return FormInterface<array<string, mixed>|null>
     */
    private function adminForm(?array $data): FormInterface
    {
        return $this->factory->create(AutopayGatewayConfigurationType::class, $data, ['validation_groups' => ['sylius']]);
    }
}
