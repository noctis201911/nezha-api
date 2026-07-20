<?php

namespace App\CentralLogics\MerchantDirectPayment;

enum MerchantDirectPaymentChannel: string
{
    case ALIPAY_QR = 'alipay_qr';
    case USDT_TRC20 = 'usdt_trc20';
    case USDT_BEP20 = 'usdt_bep20';

    public function network(): ?string
    {
        return match ($this) {
            self::ALIPAY_QR => null,
            self::USDT_TRC20 => 'trc20',
            self::USDT_BEP20 => 'bep20',
        };
    }

    public function governanceNetwork(): ?string
    {
        return match ($this) {
            self::ALIPAY_QR => null,
            self::USDT_TRC20 => 'TRC20',
            self::USDT_BEP20 => 'BEP20',
        };
    }

    public function asset(): string
    {
        return $this === self::ALIPAY_QR ? 'CNY' : 'USDT';
    }

    public function tokenContract(): ?string
    {
        return match ($this) {
            self::ALIPAY_QR => null,
            self::USDT_TRC20 => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            self::USDT_BEP20 => '0x55d398326f99059ff775485246999027b3197955',
        };
    }

    public function tokenDecimals(): int
    {
        return match ($this) {
            self::ALIPAY_QR => 2,
            self::USDT_TRC20 => 6,
            self::USDT_BEP20 => 18,
        };
    }

    public function customerLabel(): string
    {
        return match ($this) {
            self::ALIPAY_QR => 'æ”¯ä»˜å®äººæ°‘å¸',
            self::USDT_TRC20 => 'USDT Â· TRONï¼ˆTRC20ï¼‰',
            self::USDT_BEP20 => 'USDT Â· BNB Smart Chainï¼ˆBEP20ï¼‰',
        };
    }
}
