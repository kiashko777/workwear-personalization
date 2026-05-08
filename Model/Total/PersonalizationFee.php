<?php

declare(strict_types=1);

namespace Workwear\Personalization\Model\Total;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;
use Magento\Store\Model\ScopeInterface;
use Workwear\Personalization\Api\Data\CustomerLogoInterface;
use Workwear\Personalization\Model\CustomerLogoFactory;
use Workwear\Personalization\Model\ResourceModel\CustomerLogo as CustomerLogoResource;

class PersonalizationFee extends AbstractTotal
{
    private const CONFIG_LOGO_FEE = 'workwear/personalization/logo_fee';
    private const CONFIG_TEXT_FEE = 'workwear/personalization/text_fee';
    private const CONTENT_TYPE_LOGO = 'LOGO';
    private const CONTENT_TYPE_TEXT = 'TEXT';

    protected $_code = 'personalization_fee';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CustomerLogoFactory $customerLogoFactory,
        private readonly CustomerLogoResource $customerLogoResource,
    ) {}

    public function collect(
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total $total
    ): static {
        parent::collect($quote, $shippingAssignment, $total);

        // Only collect once — on the shipping address pass
        if ($shippingAssignment->getShipping()->getAddress()->getAddressType() !== Quote\Address::TYPE_SHIPPING) {
            return $this;
        }

        $processedHashes = [];
        $totalFee = 0.0;

        foreach ($quote->getAllVisibleItems() as $item) {
            $json = $item->getData('personalization_data');
            if (!$json) {
                continue;
            }

            try {
                $personalizations = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!is_array($personalizations)) {
                continue;
            }

            foreach ($personalizations as $p) {
                $contentType = (string) ($p['content_type'] ?? '');

                if ($contentType === self::CONTENT_TYPE_LOGO) {
                    $totalFee += $this->calcLogoFee($p, $processedHashes);
                } elseif ($contentType === self::CONTENT_TYPE_TEXT) {
                    $totalFee += $this->calcTextFee($p, $processedHashes);
                }
            }
        }

        if ($totalFee > 0.0) {
            $total->setTotalAmount($this->getCode(), $totalFee);
            $total->setBaseTotalAmount($this->getCode(), $totalFee);
            $total->setGrandTotal((float) $total->getGrandTotal() + $totalFee);
            $total->setBaseGrandTotal((float) $total->getBaseGrandTotal() + $totalFee);
        }

        return $this;
    }

    public function fetch(Quote $quote, Total $total): array
    {
        $amount = $total->getTotalAmount($this->getCode());
        if (!$amount) {
            return [];
        }

        return [
            'code'  => $this->getCode(),
            'title' => __('Personalization Setup Fee'),
            'value' => $amount,
        ];
    }

    private function calcLogoFee(array $p, array &$processedHashes): float
    {
        $logoUid = (string) ($p['logo_uid'] ?? '');
        if ($logoUid === '') {
            return 0.0;
        }

        $logo = $this->customerLogoFactory->create();
        $this->customerLogoResource->loadByFileHash($logo, $logoUid);

        if (!$logo->getId()) {
            return 0.0;
        }

        // Approved in a previous order → waived forever
        if ((int) $logo->getStatus() === CustomerLogoInterface::STATUS_APPROVED) {
            return 0.0;
        }

        $hash = (string) $logo->getFileHash();

        // Same logo already charged earlier in this cart
        if (in_array($hash, $processedHashes, true)) {
            return 0.0;
        }

        $processedHashes[] = $hash;

        return (float) $this->scopeConfig->getValue(self::CONFIG_LOGO_FEE, ScopeInterface::SCOPE_STORE);
    }

    private function calcTextFee(array $p, array &$processedHashes): float
    {
        $textLines = $p['text_lines'] ?? [];
        if (empty($textLines)) {
            return 0.0;
        }

        $textHash = hash('sha256', implode('|', (array) $textLines));

        if (in_array($textHash, $processedHashes, true)) {
            return 0.0;
        }

        $processedHashes[] = $textHash;

        return (float) $this->scopeConfig->getValue(self::CONFIG_TEXT_FEE, ScopeInterface::SCOPE_STORE);
    }
}
