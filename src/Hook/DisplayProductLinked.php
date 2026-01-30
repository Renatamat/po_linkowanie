<?php
declare(strict_types=1);

namespace Piano\LinkedProduct\Hook;

use Db;
use Product;
use Tools;

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

    protected function shouldBlockBeDisplayed(array $params)
    {
        return (int) Tools::getValue('id_product') > 0;
    }
}
