<?php
declare(strict_types=1);

namespace Piano\LinkedProduct\Hook;

use Db;
use Image;
use Language;
use Tools;
use Product;
use Configuration;

class DisplayAdminProductsExtra extends AbstractHook
{
    public function run(array $params)
    {
        if (isset($params['id_product'])) {
            $productId = $params['id_product'];
            $db = Db::getInstance();
            $profiles = $db->executeS('SELECT id_profile, name FROM ' . _DB_PREFIX_ . 'po_link_profile WHERE active=1 ORDER BY name ASC') ?: [];
            $assignment = $db->getRow('SELECT id_profile, family_key FROM ' . _DB_PREFIX_ . 'po_link_product_family WHERE id_product=' . (int) $productId);
            $sql = 'SELECT pl.id, pl.type, pl.position FROM ' . _DB_PREFIX_ . 'po_linkedproduct pl ORDER BY pl.position ASC';
            $result = $db->executeS($sql);
            $positions = [];
            $relatedProductNames = [];
            $productImages = [];
            $languages = Language::getLanguages(false);
            foreach ($result as $row) {
                $relatedProductsData = $db->executeS('SELECT r.id, r.product_id, r.position, rl.id_lang, rl.value FROM ' . _DB_PREFIX_ . 'po_linkedproduct_row r LEFT JOIN ' . _DB_PREFIX_ . 'po_linkedproduct_row_lang rl ON r.id = rl.id_row WHERE r.group_id = ' . (int) $row['id'] . ' ORDER BY r.position ASC, r.id ASC');
                $relatedProducts = [];
                foreach ($relatedProductsData as $rp) {
                    if (!isset($relatedProducts[$rp['product_id']])) {
                        $relatedProducts[$rp['product_id']] = [
                            'product_id' => $rp['product_id'],
                            'value' => [],
                            'position' => (int) $rp['position'],
                        ];
                    }
                    if ($rp['id_lang']) {
                        $relatedProducts[$rp['product_id']]['value'][$rp['id_lang']] = $rp['value'];
                    }
                }
                if ($relatedProducts) {
                    uasort($relatedProducts, function ($a, $b) {
                        return (int) $a['position'] <=> (int) $b['position'];
                    });
                }
                $found = false;
                foreach ($relatedProducts as $relatedProduct) {
                    if ($relatedProduct['product_id'] == $productId) {
                        $found = true;
                        break;
                    }
                }
                if ($found) {
                    foreach ($relatedProducts as $relatedProduct) {
                        $productName = $db->getValue("SELECT name FROM " . _DB_PREFIX_ . "product_lang WHERE id_product = " . (int) $relatedProduct['product_id'] . " AND id_lang = " . (int) $this->context->language->id);
                        $relatedProductNames[$relatedProduct['product_id']] = $productName;
                        $image = Image::getCover($relatedProduct['product_id']);
                        $imagePath = $this->context->link->getImageLink($productName, $image['id_image'], 'small_default');
                        $productImages[$relatedProduct['product_id']] = $imagePath;
                    }
                    $titles = $db->executeS('SELECT id_lang, group_title FROM ' . _DB_PREFIX_ . 'po_linkedproduct_lang WHERE id = ' . (int) $row['id']);
                    $titleLang = [];
                    foreach ($titles as $t) {
                        $titleLang[$t['id_lang']] = $t['group_title'];
                    }
                    $positions[] = [
                        'id' => $row['id'],
                        'type' => $row['type'],
                        'group_title' => $titleLang,
                        'position' => $row['position'],
                        'related_products' => $relatedProducts,
                    ];
                }
            }
            usort($positions, function ($a, $b) {
                return (int) $a['position'] - (int) $b['position'];
            });

            $defaultGroupTitle = [];
            foreach ($languages as $lang) {
                $defaultGroupTitle[$lang['id_lang']] = Configuration::get('PO_LINKEDPRODUCT_TITLE_' . (int) $lang['id_lang']);
            }
            $this->context->smarty->assign([
                'app_js_repeater' => $this->module->getPathUri() . 'views/js/jquery.repeater.js',
                'app_js' => $this->module->getPathUri() . 'views/js/app.js',
                'app_css' => $this->module->getPathUri() . 'views/css/app.css',
                'repeater_component' => 'test-address',
                'positions' => $positions,
                'related_product_names' => $relatedProductNames,
                'product_images' => $productImages,
                'languages' => $languages,
                'default_form_language' => (int) $this->context->language->id,
                'default_group_title' => $defaultGroupTitle,
                'feature_profiles' => $profiles,
                'feature_assignment' => $assignment ?: ['id_profile' => 0, 'family_key' => ''],
            ]);
        }
        return $this->module->display($this->module->getLocalPath().'po_linkedproduct.php', 'views/templates/hook/repeater.tpl');
    }
}
