<?php
declare(strict_types=1);

namespace Piano\LinkedProduct\Hook;

use Db;
use Language;
use Tools;

class ActionProductUpdate extends AbstractHook
{
    public function run(array $params)
    {
        if (!isset($params['id_product'])) {
            return null;
        }

        $productId = (int) $params['id_product'];
        $linkingProducts = Tools::getValue('linking_products');

        if (!is_array($linkingProducts)) {
            return null;
        }

        $db = Db::getInstance();
        $existingGroups = $this->getExistingGroups($productId, $db);

        foreach ($linkingProducts as $linkingProduct) {
            $this->handleLinkingProduct($linkingProduct, $productId, $existingGroups, $db);
        }

        $this->removeExistingGroups($existingGroups, $db);

        return null;
    }

    private function getExistingGroups(int $productId, Db $db)
    {
        return $db->executeS('SELECT DISTINCT group_id FROM '._DB_PREFIX_.'po_linkedproduct_row WHERE product_id = ' . $productId);
    }

    private function handleLinkingProduct(array $linkingProduct, int $productId, array &$existingGroups, Db $db): void
    {
        if (isset($linkingProduct['related_products'])) {
            if (isset($linkingProduct['related_products'])) {
                $linkedId = $linkingProduct['linked_id'];
                $groupTitle = isset($linkingProduct['group_title']) ? $linkingProduct['group_title'] : [];
                $type = $linkingProduct['type'];
                $position = isset($linkingProduct['position']) ? (int) $linkingProduct['position'] : 0;
                $relatedProducts = $linkingProduct['related_products'];

                $currentValueForProductId = isset($relatedProducts[$productId]['value']) ? $relatedProducts[$productId]['value'] : '';
                $relatedProducts[$productId] = ['product_id' => $productId, 'value' => $currentValueForProductId];

                if (!empty($linkedId)) {
                    $db->update('po_linkedproduct', [
                        'type' => pSQL($type),
                        'position' => pSQL($position),
                    ], 'id = ' . (int) $linkedId);
                    $groupId = $linkedId;
                    foreach (Language::getLanguages(false) as $lang) {
                        $title = isset($groupTitle[$lang['id_lang']]) ? $groupTitle[$lang['id_lang']] : '';
                        $db->update('po_linkedproduct_lang', [
                            'group_title' => pSQL($title),
                        ], 'id = ' . (int) $groupId . ' AND id_lang = ' . (int) $lang['id_lang']);
                    }
                } else {
                    $db->insert('po_linkedproduct', [
                        'type' => pSQL($type),
                        'position' => pSQL($position),
                    ]);
                    $groupId = $db->Insert_ID();
                    foreach (Language::getLanguages(false) as $lang) {
                        $title = isset($groupTitle[$lang['id_lang']]) ? $groupTitle[$lang['id_lang']] : '';
                        $db->insert('po_linkedproduct_lang', [
                            'id' => (int) $groupId,
                            'id_lang' => (int) $lang['id_lang'],
                            'group_title' => pSQL($title),
                        ]);
                    }
                }

                // store position specific for this product
                $db->execute('REPLACE INTO ' . _DB_PREFIX_ . 'po_linkedproduct_position (product_id, group_id, position) VALUES (' . (int) $productId . ', ' . (int) $groupId . ', ' . (int) $position . ')');

                $rows = $db->executeS('SELECT id FROM ' . _DB_PREFIX_ . 'po_linkedproduct_row WHERE group_id = ' . (int) $groupId);
                foreach ($rows as $r) {
                    $db->delete('po_linkedproduct_row_lang', 'id_row = ' . (int) $r['id']);
                }
                $db->delete('po_linkedproduct_row', 'group_id = ' . (int) $groupId);

                $relatedProducts = array_values($relatedProducts);
                foreach ($relatedProducts as $index => $relatedProduct) {
                    $rowPosition = isset($relatedProduct['position'])
                        ? (int) $relatedProduct['position']
                        : ($index + 1);
                    $db->insert('po_linkedproduct_row', [
                        'group_id' => (int) $groupId,
                        'product_id' => (int) $relatedProduct['product_id'],
                        'position' => $rowPosition,
                        'value' => ''
                    ]);
                    $rowId = $db->Insert_ID();
                    foreach (Language::getLanguages(false) as $lang) {
                        $val = isset($relatedProduct['value'][$lang['id_lang']]) ? $relatedProduct['value'][$lang['id_lang']] : '';
                        $db->insert('po_linkedproduct_row_lang', [
                            'id_row' => (int) $rowId,
                            'id_lang' => (int) $lang['id_lang'],
                            'value' => pSQL($val),
                        ]);
                    }
                }

                $existingGroups = array_filter($existingGroups, function ($group) use ($groupId) {
                    return $group['group_id'] != $groupId;
                });
            }
        }
    }

    private function removeExistingGroups(array $existingGroups, Db $db): void
    {
        foreach ($existingGroups as $group) {
            $rows = $db->executeS('SELECT id FROM ' . _DB_PREFIX_ . 'po_linkedproduct_row WHERE group_id = ' . (int) $group['group_id']);
            foreach ($rows as $r) {
                $db->delete('po_linkedproduct_row_lang', 'id_row = ' . (int) $r['id']);
            }
            $db->delete('po_linkedproduct_row', 'group_id = ' . (int) $group['group_id']);
            $db->delete('po_linkedproduct_position', 'group_id = ' . (int) $group['group_id']);
            $db->delete('po_linkedproduct_lang', 'id = ' . (int) $group['group_id']);
            $db->delete('po_linkedproduct', 'id = ' . (int) $group['group_id']);
        }
    }

    private function removeAllGroups(int $productId, Db $db): void
    {
        $groupsToDelete = $db->executeS('SELECT DISTINCT group_id FROM '._DB_PREFIX_.'po_linkedproduct_row WHERE product_id = ' . $productId);
        foreach ($groupsToDelete as $group) {
            $rows = $db->executeS('SELECT id FROM ' . _DB_PREFIX_ . 'po_linkedproduct_row WHERE group_id = ' . (int) $group['group_id']);
            foreach ($rows as $r) {
                $db->delete('po_linkedproduct_row_lang', 'id_row = ' . (int) $r['id']);
            }
            $db->delete('po_linkedproduct_row', 'group_id = ' . (int) $group['group_id']);
            $db->delete('po_linkedproduct_position', 'group_id = ' . (int) $group['group_id']);
            $db->delete('po_linkedproduct_lang', 'id = ' . (int) $group['group_id']);
            $db->delete('po_linkedproduct', 'id = ' . (int) $group['group_id']);
        }
    }
}
