<?php
declare(strict_types=1);

namespace Piano\LinkedProduct\Hook;

use Db;
use Tools;

class DisplayProductLinkedFeatures extends AbstractDisplayHook
{
    private const TEMPLATE_FILE = 'display_product_linked.tpl';

    protected function getTemplate(): string
    {
        return self::TEMPLATE_FILE;
    }

    protected function assignTemplateVariables(array $params)
    {
        $productId = (int) Tools::getValue('id_product');

        if (!$productId) {
            return;
        }

        if (!$this->assignFeatureLinkedPositions($productId)) {
            $this->context->smarty->assign([
                'feature_positions' => [],
                'positions' => [],
                'id_lang' => (int) $this->context->language->id,
            ]);
            return;
        }
    }

    private function assignFeatureLinkedPositions(int $productId): bool
    {
        $db = Db::getInstance();
        $assignment = $db->getRow('
            SELECT id_profile, family_key
            FROM ' . _DB_PREFIX_ . 'po_link_product_family
            WHERE id_product=' . (int) $productId
        );

        if (!$assignment) {
            return false;
        }

        $profile = $db->getRow('
            SELECT id_profile, options_csv
            FROM ' . _DB_PREFIX_ . 'po_link_profile
            WHERE id_profile=' . (int) $assignment['id_profile'] . ' AND active=1
        );

        if (!$profile) {
            return false;
        }

        $optionIds = $this->parseCsvIds((string) ($profile['options_csv'] ?? ''));
        if (!$optionIds) {
            return false;
        }

        $indexRows = $db->executeS(
            'SELECT i.id_product, i.options_json, p.active
             FROM ' . _DB_PREFIX_ . 'po_link_index i
             INNER JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = i.id_product
             WHERE i.id_profile=' . (int) $profile['id_profile'] . '
               AND i.family_key=\'' . pSQL((string) $assignment['family_key']) . '\''
        ) ?: [];

        if (!$indexRows) {
            return false;
        }

        $productOptions = [];
        $featureValues = [];
        $productActive = [];
        foreach ($indexRows as $row) {
            $decoded = json_decode((string) $row['options_json'], true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $options = [];
            foreach ($decoded as $featureId => $valueId) {
                $featureId = (int) $featureId;
                if (!in_array($featureId, $optionIds, true)) {
                    continue;
                }
                $valueId = (int) $valueId;
                $options[$featureId] = $valueId;
                $featureValues[$featureId][$valueId] = true;
            }
            $productIdRow = (int) $row['id_product'];
            $productOptions[$productIdRow] = $options;
            $productActive[$productIdRow] = (bool) $row['active'];
        }

        if (!isset($productOptions[$productId])) {
            return false;
        }

        $valueIds = [];
        foreach ($featureValues as $values) {
            $valueIds = array_merge($valueIds, array_keys($values));
        }
        $valueIds = array_values(array_unique(array_filter($valueIds)));

        $valueNameMap = [];
        if ($valueIds) {
            $valueRows = $db->executeS('
                SELECT fv.id_feature, fvl.id_feature_value, fvl.value
                FROM ' . _DB_PREFIX_ . 'feature_value fv
                INNER JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
                    ON fvl.id_feature_value = fv.id_feature_value
                    AND fvl.id_lang=' . (int) $this->context->language->id . '
                WHERE fv.id_feature IN (' . implode(',', array_map('intval', $optionIds)) . ')
                  AND fv.id_feature_value IN (' . implode(',', array_map('intval', $valueIds)) . ')
            ') ?: [];
            foreach ($valueRows as $row) {
                $valueNameMap[(int) $row['id_feature']][(int) $row['id_feature_value']] = $row['value'];
            }
        }

        $featureNameRows = $db->executeS('
            SELECT id_feature, name
            FROM ' . _DB_PREFIX_ . 'feature_lang
            WHERE id_lang=' . (int) $this->context->language->id . '
              AND id_feature IN (' . implode(',', array_map('intval', $optionIds)) . ')
        ') ?: [];
        $featureNames = [];
        foreach ($featureNameRows as $row) {
            $featureNames[(int) $row['id_feature']] = $row['name'];
        }

        $labelRows = $db->executeS('
            SELECT id_feature, label
            FROM ' . _DB_PREFIX_ . 'po_link_profile_label
            WHERE id_profile=' . (int) $profile['id_profile'] . '
              AND id_lang=' . (int) $this->context->language->id . '
        ') ?: [];
        $labelMap = [];
        foreach ($labelRows as $row) {
            $labelMap[(int) $row['id_feature']] = $row['label'];
        }

        $currentOptions = $productOptions[$productId] ?? [];
        $featurePositions = [];

        foreach ($optionIds as $featureId) {
            $values = array_keys($featureValues[$featureId] ?? []);
            $valueEntries = [];
            foreach ($values as $valueId) {
                $expected = $currentOptions;
                $expected[$featureId] = $valueId;

                $targetProductId = null;
                $targetActive = false;
                foreach ($productOptions as $candidateId => $candidateOptions) {
                    $match = true;
                    foreach ($optionIds as $checkFeatureId) {
                        if (!isset($expected[$checkFeatureId])) {
                            continue;
                        }
                        if (!isset($candidateOptions[$checkFeatureId]) || $candidateOptions[$checkFeatureId] !== $expected[$checkFeatureId]) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        $candidateActive = $productActive[$candidateId] ?? false;
                        if ($candidateId === $productId) {
                            $targetProductId = $candidateId;
                            $targetActive = true;
                            break;
                        }
                        if ($candidateActive && !$targetActive) {
                            $targetProductId = $candidateId;
                            $targetActive = true;
                        } elseif ($targetProductId === null) {
                            $targetProductId = $candidateId;
                            $targetActive = $candidateActive;
                        }
                    }
                }

                $valueEntries[] = [
                    'value_id' => $valueId,
                    'label' => $valueNameMap[$featureId][$valueId] ?? (string) $valueId,
                    'product_id' => $targetProductId,
                    'active' => $targetProductId === $productId,
                    'disabled' => $targetProductId === null || !$targetActive,
                    'link' => $targetProductId ? $this->context->link->getProductLink($targetProductId) : null,
                ];
            }

            usort($valueEntries, function ($a, $b) {
                return strcmp((string) $a['label'], (string) $b['label']);
            });

            $featurePositions[] = [
                'feature_id' => $featureId,
                'title' => $labelMap[$featureId] ?? ($featureNames[$featureId] ?? ('#' . $featureId)),
                'values' => $valueEntries,
            ];
        }

        if (!$featurePositions) {
            return false;
        }

        $this->context->smarty->assign([
            'feature_positions' => $featurePositions,
            'positions' => [],
            'id_lang' => (int) $this->context->language->id,
        ]);

        return true;
    }

    private function parseCsvIds(string $csv): array
    {
        if ($csv === '') {
            return [];
        }

        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $csv))));
        $ids = array_values(array_unique(array_filter($ids, static function ($id) {
            return $id > 0;
        })));

        return $ids;
    }

    protected function shouldBlockBeDisplayed(array $params)
    {
        return (int) Tools::getValue('id_product') > 0;
    }
}
