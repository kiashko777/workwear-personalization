<?php

declare(strict_types=1);

namespace Workwear\Personalization\Plugin\Quote\Item;

use Magento\Quote\Model\Quote\Item\ToOrderItem;
use Magento\Sales\Api\Data\OrderItemInterface;

class ToOrderItemPlugin
{
    public function aroundConvert(ToOrderItem $subject, callable $proceed, $item, $data = []): OrderItemInterface
    {
        /** @var OrderItemInterface $orderItem */
        $orderItem = $proceed($item, $data);

        $json = $item->getData('personalization_data');
        if (!$json) {
            return $orderItem;
        }

        $orderItem->setData('personalization_data', $json);

        $personalizations = json_decode($json, true);
        if (!is_array($personalizations)) {
            return $orderItem;
        }

        $additionalOptions = [];
        foreach ($personalizations as $p) {
            $additionalOptions[] = [
                'label' => 'Position',
                'value' => $p['position_code'] ?? '',
            ];
            $additionalOptions[] = [
                'label' => 'Type',
                'value' => $p['application_type'] ?? '',
            ];
            $additionalOptions[] = [
                'label' => 'Content',
                'value' => $p['content_type'] ?? '',
            ];
            if (!empty($p['logo_uid'])) {
                $additionalOptions[] = [
                    'label' => 'Logo UID',
                    'value' => $p['logo_uid'],
                ];
            }
            if (!empty($p['text_lines']) && is_array($p['text_lines'])) {
                $additionalOptions[] = [
                    'label' => 'Text',
                    'value' => implode(', ', $p['text_lines']),
                ];
            }
            if (!empty($p['font_family'])) {
                $additionalOptions[] = [
                    'label' => 'Font',
                    'value' => $p['font_family'],
                ];
            }
        }

        $options = $orderItem->getProductOptions() ?? [];
        $options['additional_options'] = array_merge(
            $options['additional_options'] ?? [],
            $additionalOptions
        );
        $orderItem->setProductOptions($options);

        return $orderItem;
    }
}
