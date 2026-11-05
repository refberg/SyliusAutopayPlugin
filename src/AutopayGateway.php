<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin;

final class AutopayGateway
{
    public const FACTORY_NAME = 'refberg_autopay';

    /** Key under which the plugin keeps its data in Payment::$details. */
    public const DETAILS_KEY = 'refberg_autopay';

    public const CONFIG_ENVIRONMENT = 'environment';

    public const CONFIG_SERVICE_ID = 'service_id';

    public const CONFIG_SHARED_KEY = 'shared_key';

    public const CONFIG_HASH_ALGORITHM = 'hash_algorithm';

    /** The one currency of the Autopay service ID (Autopay default: PLN). */
    public const CONFIG_CURRENCY = 'currency';

    /** Currencies Autopay accepts. */
    public const CURRENCIES = ['PLN', 'EUR', 'GBP', 'USD'];

    public const DEFAULT_CURRENCY = 'PLN';

    private function __construct()
    {
    }
}
