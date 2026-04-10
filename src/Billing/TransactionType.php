<?php

declare(strict_types=1);

namespace Utopia\Billing;

/**
 * TransactionType
 *
 * Backed enum for the closed set of transaction types.
 * These define how money moves through the system.
 */
enum TransactionType: string
{
    case GatewayCharge = 'gateway_charge';
    case GatewayRefund = 'gateway_refund';
    case WalletTopup = 'wallet_topup';
    case WalletDeduction = 'wallet_deduction';
    case WalletRefund = 'wallet_refund';
    case CouponCredit = 'coupon_credit';
    case CreditExpiry = 'credit_expiry';

    /**
     * Check if this is a wallet-related transaction type.
     */
    public function isWallet(): bool
    {
        return match ($this) {
            self::WalletTopup,
            self::WalletDeduction,
            self::WalletRefund => true,
            default => false,
        };
    }

    /**
     * Check if this is a gateway-related transaction type.
     */
    public function isGateway(): bool
    {
        return match ($this) {
            self::GatewayCharge,
            self::GatewayRefund => true,
            default => false,
        };
    }

    /**
     * Check if this is a credit-related transaction type.
     */
    public function isCredit(): bool
    {
        return match ($this) {
            self::CouponCredit,
            self::CreditExpiry => true,
            default => false,
        };
    }
}
