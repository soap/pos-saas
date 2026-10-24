<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentProviderInterface;
use App\Support\Errors\ApiException;

class PaymentProviderRegistry
{
    /** @var array<string,PaymentProviderInterface> */
    private array $resolved = [];

    public function get(string $code): PaymentProviderInterface
    {
        if (! isset($this->resolved[$code])) {
            $config = config("pos.payments.providers.{$code}");
            if (! $config) {
                throw ApiException::notFound('provider_not_found', 'ไม่พบ payment provider');
            }
            $this->resolved[$code] = new $config['class']($config);
        }

        return $this->resolved[$code];
    }

    public function defaultQr(): PaymentProviderInterface
    {
        return $this->get((string) config('pos.payments.default_qr_provider'));
    }
}
