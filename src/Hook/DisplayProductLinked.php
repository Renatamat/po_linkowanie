<?php
declare(strict_types=1);

namespace Piano\LinkedProduct\Hook;

use Db;
use Product;
use Tools;
use Configuration;

class DisplayProductLinked extends AbstractDisplayHook
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

        $mode = (string) Configuration::get('PO_LINKEDPRODUCT_LINKING_MODE');
        if ($mode === 'features') {
            if ($this->assignFeatureLinkedPositions($productId)) {
                return;
            }

            $this->context->smarty->assign([
                'feature_positions' => [],
                'positions' => [],
                'id_lang' => (int) $this->context->language->id,
            ]);
            return;
        }

        $db = Db::getInstance();
        $sql = 'SELECT DISTINCT pl.id, pl.type, COALESCE(pp.position, pl.position) AS position, pll.group_title '
            . 'FROM ' . _DB_PREFIX_ . 'po_linkedproduct pl '
            . 'INNER JOIN ' . _DB_PREFIX_ . 'po_linkedproduct_row pr ON pr.group_id = pl.id AND pr.product_id = ' . (int) $productId . ' '
            . 'LEFT JOIN ' . _DB_PREFIX_ . 'po_linkedproduct_position pp ON pl.id = pp.group_id AND pp.product_id = ' . (int) $productId . ' '
            . 'LEFT JOIN ' . _DB_PREFIX_ . 'po_linkedproduct_lang pll ON pl.id = pll.id AND pll.id_lang = ' . (int) $this->context->language->id .' '
            . 'ORDER BY position ASC';

        $result = $db->executeS($sql);
        $positions = [];
        $relatedProductNames = [];
        $relatedProductImages = [];
        foreach ($result as $row) {
            $relatedProductsData = $db->executeS(
                'SELECT r.product_id, rl.value, p.active ' .
                'FROM ' . _DB_PREFIX_ . 'po_linkedproduct_row r ' .
                'LEFT JOIN ' . _DB_PREFIX_ . 'po_linkedproduct_row_lang rl ON r.id = rl.id_row AND rl.id_lang = ' . (int) $this->context->language->id . ' ' .
                'INNER JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = r.product_id ' .
                'WHERE r.group_id = ' . (int) $row['id'] . ' ' .
                'ORDER BY r.position ASC, r.id ASC'
            );
            $relatedProducts = [];
            $titles = $db->executeS('SELECT id_lang, group_title FROM ' . _DB_PREFIX_ . 'po_linkedproduct_lang WHERE id = ' . (int) $row['id']);
            $titleLang = [];
            foreach ($titles as $t) {
                $titleLang[$t['id_lang']] = $t['group_title'];
            }
            foreach ($relatedProductsData as $relatedProduct) {
                $productName = $db->getValue("SELECT name FROM " . _DB_PREFIX_ . "product_lang WHERE id_product = " . (int) $relatedProduct['product_id'] . " AND id_lang = " . (int) $this->context->language->id);
                $relatedProductNames[$relatedProduct['product_id']] = $productName;
                $displayValue = $relatedProduct['value'] ?: $productName;

                $product = new Product((int) $relatedProduct['product_id'], false, (int) $this->context->language->id);
                $imageData = $db->getRow("SELECT il.* FROM " . _DB_PREFIX_ . "image_lang il JOIN " . _DB_PREFIX_ . "image i ON il.id_image = i.id_image WHERE i.id_product = " . (int) $relatedProduct['product_id'] . " AND il.legend LIKE '_0%'");
                if ($imageData) {
                    $link = $this->context->link->getImageLink($product->link_rewrite, $imageData['id_image'], 'small_default');
                } else {
                    $cover = Product::getCover((int) $product->id);
                    if ($cover) {
                        $link = $this->context->link->getImageLink($product->link_rewrite, $cover['id_image'], 'small_default');
                    } else {
                        $link = null;
                    }
                }
                if ($link) {
                    $relatedProductImages[$relatedProduct['product_id']] = $link;
                }

                $relatedProducts[] = [
                    'product_id' => $relatedProduct['product_id'],
                    'value' => $relatedProduct['value'],
                    'display_value' => $displayValue,
                    'disabled' => !(bool) $relatedProduct['active'],
                ];
            }
            $positions[] = [
                'id' => $row['id'],
                'type' => $row['type'],
                'group_title' => $titleLang,
                'position' => $row['position'],
                'related_products' => $relatedProducts,
            ];
        }
//        usort($positions, function ($a, $b) {
//            return $a['position'] - $b['position'];
//        });
        
        $this->context->smarty->assign([
            'positions' => $positions,
            'related_product_names' => $relatedProductNames,
            'related_product_images' => $relatedProductImages,
            'id_lang' => (int) $this->context->language->id,
        ]);
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

        foreach ($optionIds as $optionId) {
            if (!isset($productOptions[$productId][$optionId])) {
                return false;
            }
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

        $currentOptions = $productOptions[$productId];
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
