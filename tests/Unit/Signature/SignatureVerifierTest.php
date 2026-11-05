<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\Signature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Refberg\SyliusAutopayPlugin\Config\Environment;
use Refberg\SyliusAutopayPlugin\Config\HashAlgorithm;
use Refberg\SyliusAutopayPlugin\Signature\SignatureVerifier;
use function hash;
use function strtoupper;

/**
 * Test vectors come from the test suite of the official Autopay PHP SDK (bluepayment-plugin/bm-sdk).
 */
final class SignatureVerifierTest extends TestCase
{
    private const SDK_SHARED_KEY = 'QCBm3N0oFjzQAWsTIVN8mPLK12TW6HU6InSfjvnF';

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function sdkVectors(): iterable
    {
        yield 'ITN' => [
            ['123456', '123', '102686', '500.00', 'PLN', '106', '20200621171238', 'SUCCESS', 'ACCEPTED'],
            '422fbd7c1bbac1eb745b647980dd63c2e8ccf261ea196739ab8043eff8dd4e17',
        ];
        yield 'ITN confirmation' => [['123456', '123', 'CONFIRMED'], '7194a50431ce359cc4c201a0f154400f89f49f765b8eeb60299fc6fee1a01228'];
        yield 'transaction continue response' => [
            ['PENDING', 'https://pay-accept.bm.pl/payment/continue/9IA2UISN/718GTV5E', '123', '9IA2UISN'],
            'a3700f934854728035ae2b22cbd0dcbbad0c207faecb16f707b9d2abb103f54e',
        ];
    }

    /**
     * @param list<string> $values
     */
    #[DataProvider('sdkVectors')]
    public function test_it_matches_the_autopay_sdk(array $values, string $hash): void
    {
        $verifier = new SignatureVerifier();
        $credentials = self::credentials(self::SDK_SHARED_KEY);

        self::assertSame($hash, $verifier->sign($values, $credentials));
        self::assertTrue($verifier->verify($values, $hash, $credentials));
        self::assertTrue($verifier->verify($values, ' ' . strtoupper($hash) . "\n", $credentials));
    }

    public function test_empty_values_are_skipped(): void
    {
        $credentials = self::credentials('key');

        self::assertSame(hash('sha256', 'a|b|key'), (new SignatureVerifier())->sign(['a', '', 'b'], $credentials));
    }

    public function test_it_uses_the_configured_algorithm(): void
    {
        $credentials = new AutopayCredentials('1', 'key', HashAlgorithm::Sha512, Environment::Sandbox);

        self::assertSame(hash('sha512', '1|key'), (new SignatureVerifier())->sign(['1'], $credentials));
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['  '])]
    #[TestWith(['0000000000000000000000000000000000000000000000000000000000000000'])]
    public function test_missing_or_wrong_hash_is_rejected(?string $hash): void
    {
        self::assertFalse((new SignatureVerifier())->verify(['1', '2'], $hash, self::credentials('key')));
    }

    public function test_another_key_or_value_order_is_rejected(): void
    {
        $verifier = new SignatureVerifier();
        $hash = $verifier->sign(['1', '2'], self::credentials('key'));

        self::assertFalse($verifier->verify(['1', '2'], $hash, self::credentials('other')));
        self::assertFalse($verifier->verify(['2', '1'], $hash, self::credentials('key')));
    }

    public function test_empty_shared_key_never_verifies(): void
    {
        $credentials = self::credentials('');

        self::assertFalse((new SignatureVerifier())->verify(['1'], hash('sha256', '1|'), $credentials));
    }

    private static function credentials(string $sharedKey): AutopayCredentials
    {
        return new AutopayCredentials('123456', $sharedKey, HashAlgorithm::Sha256, Environment::Sandbox);
    }
}
