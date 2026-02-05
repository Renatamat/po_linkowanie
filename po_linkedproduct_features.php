<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class Po_linkedproduct_features extends Module
{
    /** @var string */
    protected $_html = '';

    public function __construct()
    {
        $this->name = 'po_linkedproduct_features';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Przemysław Markiewicz';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Linkowanie po cechach i rodzinie');
        $this->description = $this->l('Moduł do linkowania produktów po cechach i rodzinie.');

        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->languages = \Language::getLanguages(false);
    }

    public function install()
    {
        include dirname(__FILE__) . '/sql/features_install.php';

        return parent::install()
            && $this->registerHook('displayAdminProductsExtra')
            && $this->registerHook('actionProductUpdate')
            && $this->registerHook('actionObjectProductAddAfter')
            && $this->registerHook('actionObjectProductUpdateAfter');
    }

    public function uninstall()
    {
        include dirname(__FILE__) . '/sql/features_uninstall.php';

        return parent::uninstall();
    }

    public function getContent()
    {
        $this->_html = '';

        if (Tools::isSubmit('lp_action')) {
            $action = (string) Tools::getValue('lp_action');
            try {
                switch ($action) {
                    case 'save_profile':
                        $result = $this->saveProfileFromRequest();
                        $this->_html .= $this->displayConfirmation(
                            $result['is_new'] ? $this->l('Profil został dodany.') : $this->l('Profil został zapisany.')
                        );
                        break;
                    case 'delete_profile':
                        $profileId = (int) Tools::getValue('profile_id');
                        if ($profileId > 0) {
                            $this->deleteProfile($profileId);
                            $this->_html .= $this->displayConfirmation($this->l('Profil został usunięty.'));
                        }
                        break;
                    case 'rebuild_index':
                        $count = $this->rebuildFeatureIndex();
                        $this->_html .= $this->displayConfirmation(
                            $this->l('Indeks został przebudowany dla produktów: ') . (int) $count
                        );
                        break;
                }
            } catch (\Exception $e) {
                $this->_html .= $this->displayError($this->l('Błąd akcji: ') . $e->getMessage());
            }
        }

        return $this->_html . $this->renderFeatureProfiles();
    }

    public function hookDisplayAdminProductsExtra($params)
    {
        if (!isset($params['id_product'])) {
            return '';
        }

        $productId = (int) $params['id_product'];
        $db = \Db::getInstance();
        $profiles = $db->executeS('SELECT id_profile, name FROM ' . _DB_PREFIX_ . 'po_link_profile WHERE active=1 ORDER BY name ASC') ?: [];
        $assignment = $db->getRow('SELECT id_profile, family_key FROM ' . _DB_PREFIX_ . 'po_link_product_family WHERE id_product=' . (int) $productId);

        $this->context->smarty->assign([
            'feature_profiles' => $profiles,
            'feature_assignment' => $assignment ?: ['id_profile' => 0, 'family_key' => ''],
        ]);

        return $this->display($this->getLocalPath() . 'po_linkedproduct_features.php', 'views/templates/hook/features_assignment.tpl');
    }

    public function hookActionProductUpdate($params)
    {
        if (!isset($params['id_product'])) {
            return null;
        }

        $productId = (int) $params['id_product'];
        $this->saveProductFamilyAssignmentFromRequest($productId);
        $this->updateFeatureIndexForProduct($productId);

        return null;
    }

    public function hookActionObjectProductAddAfter($params)
    {
        $productId = 0;
        if (isset($params['object']) && isset($params['object']->id)) {
            $productId = (int) $params['object']->id;
        } elseif (isset($params['id_product'])) {
            $productId = (int) $params['id_product'];
        }

        if ($productId > 0) {
            $this->saveProductFamilyAssignmentFromRequest($productId);
            $this->updateFeatureIndexForProduct($productId);
        }

        return null;
    }

    public function hookActionObjectProductUpdateAfter($params)
    {
        $productId = 0;
        if (isset($params['object']) && isset($params['object']->id)) {
            $productId = (int) $params['object']->id;
        } elseif (isset($params['id_product'])) {
            $productId = (int) $params['id_product'];
        }

        if ($productId > 0) {
            $this->saveProductFamilyAssignmentFromRequest($productId);
            $this->updateFeatureIndexForProduct($productId);
        }

        return null;
    }

    protected function renderFeatureProfiles(): string
    {
        $db = \Db::getInstance();
        $profiles = $db->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'po_link_profile ORDER BY id_profile DESC') ?: [];
        $featureOptions = $this->getFeatureOptions((int) $this->context->language->id);
        $languages = \Language::getLanguages(false);

        $profileId = (int) Tools::getValue('profile_id');
        $profile = null;
        $labelMap = [];
        if ($profileId > 0) {
            $profile = $db->getRow('SELECT * FROM ' . _DB_PREFIX_ . 'po_link_profile WHERE id_profile=' . (int) $profileId);
            $labels = $db->executeS('SELECT id_feature, id_lang, label FROM ' . _DB_PREFIX_ . 'po_link_profile_label WHERE id_profile=' . (int) $profileId) ?: [];
            foreach ($labels as $label) {
                $labelMap[(int) $label['id_feature']][(int) $label['id_lang']] = $label['label'];
            }
        }

        $selectedOptions = $this->parseCsvIds($profile['options_csv'] ?? '');
        $selectedFamily = $this->parseCsvIds($profile['family_csv'] ?? '');

        $output = '<div class="panel">
            <div class="panel-heading">' . $this->l('Profile linkowania po cechach') . '</div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>' . $this->l('ID') . '</th>
                            <th>' . $this->l('Nazwa') . '</th>
                            <th>' . $this->l('Options CSV') . '</th>
                            <th>' . $this->l('Family CSV') . '</th>
                            <th>' . $this->l('Aktywny') . '</th>
                            <th>' . $this->l('Akcje') . '</th>
                        </tr>
                    </thead>
                    <tbody>';

        if (!$profiles) {
            $output .= '<tr><td colspan="6">' . $this->l('Brak profili.') . '</td></tr>';
        } else {
            foreach ($profiles as $p) {
                $output .= '<tr>
                    <td>#' . (int) $p['id_profile'] . '</td>
                    <td>' . htmlspecialchars((string) $p['name']) . '</td>
                    <td>' . htmlspecialchars((string) $p['options_csv']) . '</td>
                    <td>' . htmlspecialchars((string) ($p['family_csv'] ?? '')) . '</td>
                    <td>' . ((int) $p['active'] === 1 ? '✅' : '❌') . '</td>
                    <td>
                        <a class="btn btn-default btn-xs" href="' . $this->context->link->getAdminLink('AdminModules', true)
                            . '&configure=' . $this->name . '&profile_id=' . (int) $p['id_profile'] . '">' . $this->l('Edytuj') . '</a>
                        <form method="post" style="display:inline-block" onsubmit="return confirm(\'' . $this->l('Usunąć profil?') . '\');">
                            <input type="hidden" name="lp_action" value="delete_profile">
                            <input type="hidden" name="profile_id" value="' . (int) $p['id_profile'] . '">
                            <button type="submit" class="btn btn-danger btn-xs">' . $this->l('Usuń') . '</button>
                        </form>
                    </td>
                </tr>';
            }
        }

        $output .= '</tbody></table></div></div>';

        $output .= '<form method="post" class="defaultForm form-horizontal">
            <input type="hidden" name="lp_action" value="save_profile">
            <input type="hidden" name="profile_id" value="' . (int) $profileId . '">
            <div class="panel">
                <div class="panel-heading">' . ($profileId ? $this->l('Edytuj profil') : $this->l('Dodaj profil')) . '</div>
                <div class="form-group">
                    <label class="control-label col-lg-3">' . $this->l('Nazwa') . '</label>
                    <div class="col-lg-9">
                        <input type="text" name="profile_name" class="form-control" value="' . htmlspecialchars((string) ($profile['name'] ?? '')) . '" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">' . $this->l('Cechy OPTIONS (max 3)') . '</label>
                    <div class="col-lg-9">
                        <select name="profile_options[]" class="form-control" multiple>';

        foreach ($featureOptions as $featureId => $featureName) {
            $output .= '<option value="' . (int) $featureId . '"' . (in_array($featureId, $selectedOptions, true) ? ' selected' : '') . '>'
                . htmlspecialchars($featureName) . '</option>';
        }

        $output .= '        </select>
                        <p class="help-block">' . $this->l('Wybierz 1-3 cechy do przełączników.') . '</p>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">' . $this->l('Cechy rodziny (opcjonalnie)') . '</label>
                    <div class="col-lg-9">
                        <select name="profile_family[]" class="form-control" multiple>';

        foreach ($featureOptions as $featureId => $featureName) {
            $output .= '<option value="' . (int) $featureId . '"' . (in_array($featureId, $selectedFamily, true) ? ' selected' : '') . '>'
                . htmlspecialchars($featureName) . '</option>';
        }

        $output .= '        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">' . $this->l('Aktywny') . '</label>
                    <div class="col-lg-9">
                        <input type="checkbox" name="profile_active" value="1"' . ((int) ($profile['active'] ?? 1) === 1 ? ' checked' : '') . '>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">' . $this->l('Nagłówki (override)') . '</label>
                    <div class="col-lg-9">';

        if (!$selectedOptions) {
            $output .= '<p class="help-block">' . $this->l('Wybierz cechy w OPTIONS, aby ustawić własne nagłówki.') . '</p>';
        } else {
            $output .= '<div class="table-responsive"><table class="table">
                <thead><tr><th>' . $this->l('Cecha') . '</th>';
            foreach ($languages as $lang) {
                $output .= '<th>' . htmlspecialchars($lang['iso_code']) . '</th>';
            }
            $output .= '</tr></thead><tbody>';
            foreach ($selectedOptions as $featureId) {
                $output .= '<tr><td>' . htmlspecialchars($featureOptions[$featureId] ?? ('#' . $featureId)) . '</td>';
                foreach ($languages as $lang) {
                    $value = $labelMap[$featureId][$lang['id_lang']] ?? '';
                    $output .= '<td><input type="text" class="form-control" name="profile_label[' . (int) $featureId . '][' . (int) $lang['id_lang'] . ']" value="' . htmlspecialchars((string) $value) . '"></td>';
                }
                $output .= '</tr>';
            }
            $output .= '</tbody></table></div>';
        }

        $output .= '        </div>
                </div>
                <div class="panel-footer">
                    <button type="submit" class="btn btn-default pull-right">
                        <i class="process-icon-save"></i> ' . $this->l('Save') . '
                    </button>
                </div>
            </div>
        </form>';

        $output .= '<form method="post" class="defaultForm">
            <input type="hidden" name="lp_action" value="rebuild_index">
            <button type="submit" class="btn btn-primary" onclick="return confirm(\'' . $this->l('Przebudować indeks dla wszystkich produktów?') . '\');">
                ' . $this->l('Przebuduj indeks') . '
            </button>
        </form>';

        return $output;
    }

    protected function saveProfileFromRequest(): array
    {
        $db = \Db::getInstance();
        $profileId = (int) Tools::getValue('profile_id');
        $name = trim((string) Tools::getValue('profile_name'));
        $options = Tools::getValue('profile_options', []);
        $family = Tools::getValue('profile_family', []);
        $active = Tools::getValue('profile_active') ? 1 : 0;

        if ($name === '') {
            throw new \RuntimeException($this->l('Nazwa profilu jest wymagana.'));
        }

        if (!is_array($options)) {
            $options = [];
        }

        if (!is_array($family)) {
            $family = [];
        }

        $optionsCsv = $this->buildCsv($options);
        $optionIds = $this->parseCsvIds($optionsCsv);

        if (count($optionIds) < 1 || count($optionIds) > 3) {
            throw new \RuntimeException($this->l('Wybierz od 1 do 3 cech w OPTIONS.'));
        }

        $familyCsv = $this->buildCsv($family);

        $data = [
            'name' => pSQL($name),
            'options_csv' => pSQL($optionsCsv),
            'family_csv' => $familyCsv !== '' ? pSQL($familyCsv) : null,
            'active' => (int) $active,
        ];

        $isNew = false;
        if ($profileId > 0) {
            $db->update('po_link_profile', $data, 'id_profile=' . (int) $profileId);
        } else {
            $db->insert('po_link_profile', $data);
            $profileId = (int) $db->Insert_ID();
            $isNew = true;
        }

        $db->delete('po_link_profile_label', 'id_profile=' . (int) $profileId);
        $labels = Tools::getValue('profile_label', []);
        if (is_array($labels)) {
            foreach ($labels as $featureId => $langs) {
                if (!is_array($langs)) {
                    continue;
                }
                foreach ($langs as $langId => $label) {
                    $label = trim((string) $label);
                    if ($label === '') {
                        continue;
                    }
                    $db->insert('po_link_profile_label', [
                        'id_profile' => (int) $profileId,
                        'id_feature' => (int) $featureId,
                        'id_lang' => (int) $langId,
                        'label' => pSQL($label),
                    ]);
                }
            }
        }

        return ['id' => $profileId, 'is_new' => $isNew];
    }

    protected function deleteProfile(int $profileId): void
    {
        if ($profileId <= 0) {
            return;
        }

        $db = \Db::getInstance();
        $db->delete('po_link_profile_label', 'id_profile=' . (int) $profileId);
        $db->delete('po_link_profile', 'id_profile=' . (int) $profileId);
        $db->delete('po_link_product_family', 'id_profile=' . (int) $profileId);
        $db->delete('po_link_index', 'id_profile=' . (int) $profileId);
    }

    protected function getFeatureOptions(int $idLang): array
    {
        $options = [];
        foreach (\Feature::getFeatures($idLang) as $feature) {
            $id = (int) ($feature['id_feature'] ?? 0);
            $name = (string) ($feature['name'] ?? '');
            if ($id > 0 && $name !== '') {
                $options[$id] = $name;
            }
        }

        return $options;
    }

    protected function parseCsvIds(?string $csv): array
    {
        if (!$csv) {
            return [];
        }

        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $csv))));
        $ids = array_values(array_unique(array_filter($ids, static function ($id) {
            return $id > 0;
        })));

        return $ids;
    }

    protected function buildCsv(array $ids): string
    {
        $clean = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
            return $id > 0;
        })));

        return implode(',', $clean);
    }

    public function updateFeatureIndexForProduct(int $productId): void
    {
        if ($productId <= 0) {
            return;
        }

        $db = \Db::getInstance();
        $assignment = $db->getRow('
            SELECT id_profile, family_key
            FROM ' . _DB_PREFIX_ . 'po_link_product_family
            WHERE id_product=' . (int) $productId
        );

        if (!$assignment) {
            $db->delete('po_link_index', 'id_product=' . (int) $productId);
            return;
        }

        $profile = $db->getRow('
            SELECT id_profile, options_csv
            FROM ' . _DB_PREFIX_ . 'po_link_profile
            WHERE id_profile=' . (int) $assignment['id_profile'] . ' AND active=1
        ');

        if (!$profile) {
            $db->delete('po_link_index', 'id_product=' . (int) $productId);
            return;
        }

        $optionIds = $this->parseCsvIds($profile['options_csv'] ?? '');
        if (!$optionIds) {
            $db->delete('po_link_index', 'id_product=' . (int) $productId);
            return;
        }

        $featureRows = $db->executeS(
            'SELECT id_feature, id_feature_value
             FROM ' . _DB_PREFIX_ . 'feature_product
             WHERE id_product=' . (int) $productId . '
               AND id_feature IN (' . implode(',', array_map('intval', $optionIds)) . ')'
        ) ?: [];

        $optionsMap = [];
        foreach ($featureRows as $row) {
            $optionsMap[(int) $row['id_feature']] = (int) $row['id_feature_value'];
        }

        $optionsJson = json_encode($optionsMap, JSON_UNESCAPED_UNICODE);
        if ($optionsJson === false) {
            $optionsJson = '{}';
        }

        $db->execute('REPLACE INTO ' . _DB_PREFIX_ . "po_link_index (id_product, id_profile, family_key, options_json)
            VALUES (" . (int) $productId . ", " . (int) $assignment['id_profile'] . ", '" . pSQL((string) $assignment['family_key']) . "', '" . pSQL($optionsJson) . "')");
    }

    public function rebuildFeatureIndex(): int
    {
        $db = \Db::getInstance();
        $rows = $db->executeS('SELECT id_product FROM ' . _DB_PREFIX_ . 'po_link_product_family') ?: [];
        $count = 0;
        foreach ($rows as $row) {
            $this->updateFeatureIndexForProduct((int) $row['id_product']);
            $count++;
        }

        return $count;
    }

    public function saveProductFamilyAssignmentFromRequest(int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }

        $profileId = (int) Tools::getValue('po_link_profile_id');
        $familyKey = trim((string) Tools::getValue('po_link_family_key'));
        if (Tools::strlen($familyKey) > 64) {
            $familyKey = Tools::substr($familyKey, 0, 64);
        }

        $db = \Db::getInstance();

        if ($profileId > 0 && $familyKey !== '') {
            $db->execute('REPLACE INTO ' . _DB_PREFIX_ . "po_link_product_family (id_product, id_profile, family_key, updated_at)
                VALUES (" . (int) $productId . ", " . (int) $profileId . ", '" . pSQL($familyKey) . "', NOW())");
            $this->assignFamilyByReferencePrefix($profileId, $familyKey);
            return true;
        }

        $db->delete('po_link_product_family', 'id_product=' . (int) $productId);
        return false;
    }

    protected function assignFamilyByReferencePrefix(int $profileId, string $referencePrefix): void
    {
        if ($profileId <= 0 || $referencePrefix === '') {
            return;
        }

        $db = \Db::getInstance();
        $likePrefix = pSQL(addcslashes($referencePrefix, '%_'));
        $rows = $db->executeS('
            SELECT id_product
            FROM ' . _DB_PREFIX_ . 'product
            WHERE reference LIKE "' . $likePrefix . '%"
        ') ?: [];

        if (!$rows) {
            return;
        }

        $values = [];
        foreach ($rows as $row) {
            $values[] = '(' . (int) $row['id_product'] . ', ' . (int) $profileId . ", '" . pSQL($referencePrefix) . "', NOW())";
        }

        $chunks = array_chunk($values, 200);
        foreach ($chunks as $chunk) {
            $db->execute('REPLACE INTO ' . _DB_PREFIX_ . 'po_link_product_family (id_product, id_profile, family_key, updated_at) VALUES ' . implode(',', $chunk));
        }

        foreach ($rows as $row) {
            $this->updateFeatureIndexForProduct((int) $row['id_product']);
        }
    }
}
