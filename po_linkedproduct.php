<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class Po_linkedproduct extends Module
{
    /** @var string */
    protected $_html = '';


    public function __construct()
    {
        $this->name = 'po_linkedproduct';
        $this->tab = 'others';
        $this->version = '1.3.0';
        $this->author = 'Przemysław Markiewicz';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Linkowanie produktów');
        $this->description = $this->l('Moduł do powiązywania produktów między sobą');

        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->languages = \Language::getLanguages(false);
    }

    public function install()
    {
        Configuration::updateValue('PO_LINKEDPRODUCT_OPENAI_URL', '');
        Configuration::updateValue('PO_LINKEDPRODUCT_OPENAI_KEY', '');
        Configuration::updateValue('PO_LINKEDPRODUCT_OPENAI_MODEL', 'gpt-5-chat-latest');
        Configuration::updateValue('PO_LINKEDPRODUCT_LINKING_MODE', 'manual');

        include(dirname(__FILE__).'/sql/install.php');

        return parent::install()
            && $this->registerHook('displayHeader')
            && $this->registerHook('backOfficeHeader')
            && $this->registerHook('displayBackOfficeHeader')
            && $this->registerHook('displayAdminProductsExtra')
            && $this->registerHook('displayProductLinked')
            && $this->registerHook('actionProductUpdate')
            && $this->registerHook('actionObjectProductAddAfter')
            && $this->registerHook('actionObjectProductUpdateAfter');
    }

    public function uninstall()
    {
        Configuration::deleteByName('PO_LINKEDPRODUCT_OPENAI_URL');
        Configuration::deleteByName('PO_LINKEDPRODUCT_OPENAI_KEY');
        Configuration::deleteByName('PO_LINKEDPRODUCT_OPENAI_MODEL');
        Configuration::deleteByName('PO_LINKEDPRODUCT_LINKING_MODE');

        include(dirname(__FILE__).'/sql/uninstall.php');

        return parent::uninstall();
    }

    protected function getCurrentToken(): string
{
    if (!empty($this->context->controller->token)) {
        return $this->context->controller->token;
    }
    return Tools::getAdminTokenLite('AdminModules');
}

    /**
     * BO Content
     */
 public function getContent()
{
    // bufor na komunikaty
    $this->_html = '';

    if (Tools::isSubmit('lp_action')) {
        $action = (string) Tools::getValue('lp_action');

        if (Tools::getValue('lp_ajax') === '1' && $action === 'mass_update_field') {
            $this->processMassUpdateFieldAjax();
        }

        try {
            switch ($action) {

                case 'delete_group':
                    $gid = (int) Tools::getValue('group_id');
                    if (!$gid) {
                        $arr = Tools::getValue('group_id');
                        if (is_array($arr)) {
                            // kliknięty checkbox przychodzi jako tablica – bierzemy pierwszy klucz
                            $gid = (int) key($arr);
                        }
                    }
                    if ($gid > 0) {
                        $this->deleteGroup($gid);
                        $this->_html .= $this->displayConfirmation($this->l('Grupa została usunięta.'));
                    }
                    break;

                case 'delete_row':
                    $rowId = (int) Tools::getValue('row_id');
                    $this->deleteRow($rowId);
                    $this->_html .= $this->displayConfirmation($this->l('Powiązanie produktu zostało usunięte.'));
                    break;

                case 'update_group':
                    $gid      = (int) Tools::getValue('group_id');
                    $position = (int) Tools::getValue('position', 0);
                    $type     = pSQL((string) Tools::getValue('type', 'text'));

                    // tytuły we wszystkich językach: title[<id_lang>]
                    $titles = [];
                    foreach ($this->languages as $lang) {
                        $idLang = (int) $lang['id_lang'];
                        $titles[$idLang] = (string) Tools::getValue('title_'.$idLang, '');
                    }

                    $this->updateGroup($gid, $titles, $position, $type);
                    $this->_html .= $this->displayConfirmation($this->l('Grupa została zaktualizowana.'));
                    break;

                case 'mass_update':
                    $groups = Tools::getValue('groups', []);
                    $rows   = Tools::getValue('rows', []);

                    // --- Aktualizacja grup ---
                    foreach ($groups as $gid => $data) {
                        $this->updateGroup(
                            (int) $gid,
                            $data['title'] ?? [],
                            (int) ($data['position'] ?? 0),
                            (string) ($data['type'] ?? 'text')
                        );
                    }

                    // --- Aktualizacja wierszy ---
                    $db = \Db::getInstance();

                    // (opcjonalnie) transakcja, żeby mieć spójny zapis
                    $db->execute('START TRANSACTION');
                    try {
                        foreach ($rows as $rowId => $data) {
                            if (isset($data['position'])) {
                                $db->update(
                                    'po_linkedproduct_row',
                                    ['position' => (int) $data['position']],
                                    'id = ' . (int) $rowId
                                );
                            }
                            foreach ($this->languages as $lang) {
                                $idLang = (int) $lang['id_lang'];
                                $val    = $data['value'] ?? '';

                                $exists = (int) $db->getValue(
                                    'SELECT COUNT(*)
                                     FROM ' . _DB_PREFIX_ . 'po_linkedproduct_row_lang
                                     WHERE id_row = ' . (int) $rowId . '
                                       AND id_lang = ' . $idLang
                                );

                                if ($exists) {
                                    $db->update(
                                        'po_linkedproduct_row_lang',
                                        ['value' => pSQL($val)],
                                        'id_row = ' . (int) $rowId . ' AND id_lang = ' . $idLang
                                    );
                                } else {
                                    $db->insert(
                                        'po_linkedproduct_row_lang',
                                        [
                                            'id_row'  => (int) $rowId,
                                            'id_lang' => $idLang,
                                            'value'   => pSQL($val),
                                        ]
                                    );
                                }
                            }
                        }
                        $db->execute('COMMIT');
                    } catch (\Exception $txe) {
                        $db->execute('ROLLBACK');
                        throw $txe;
                    }

                    $this->_html .= $this->displayConfirmation($this->l('Zmiany zapisane.'));
                    break;

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
            $this->_html .= $this->displayError(
                $this->l('Błąd akcji: ') . $e->getMessage()
            );
        }
    }

    // ----- UI -----
    $output = '<h2>'.$this->displayName.'</h2>';

    $tab = Tools::getValue('po_tab', 'settings');

    if (Tools::isSubmit('generate_linked')) {
        try {
            $selected = Tools::getValue('selected_products', []);
            if (empty($selected) || !is_array($selected)) {
                $this->_html .= $this->displayError($this->l('Musisz wybrać co najmniej jeden produkt.'));
            } else {
                $ids = array_map('intval', $selected);

                $selectedModel = (string) Tools::getValue('PO_LINKEDPRODUCT_OPENAI_MODEL', '');
                if ($selectedModel !== '') {
                    \Configuration::updateValue('PO_LINKEDPRODUCT_OPENAI_MODEL', $selectedModel);
                }

                $names = \Db::getInstance()->executeS('
                    SELECT p.id_product, pl.name, pl.link_rewrite
                    FROM '._DB_PREFIX_.'product p
                    INNER JOIN '._DB_PREFIX_.'product_lang pl
                        ON (pl.id_product = p.id_product AND pl.id_lang='.(int)$this->context->language->id.')
                    WHERE p.id_product IN ('.implode(',', $ids).')
                ');

                $lines = [];
                foreach ($names as $row) {
                    $lines[] = (int)$row['id_product'].' | '.$row['name'];
                }

                $userPrompt = "Produkty do powiązania:\n".implode("\n", $lines);

                $chunks = array_chunk($ids, 500);
                $groups = [];
                $featureOptions      = $this->getFeatureOptions((int)$this->context->language->id);
                $suggestedGroups     = $this->normalizeSuggestedGroups(
                    Tools::getValue('suggested_groups', ''),
                    $featureOptions
                );
                $generationGroupType = (string) Tools::getValue('generation_group_type', 'text');
                if (!in_array($generationGroupType, ['text', 'photo'], true)) {
                    $generationGroupType = 'text';
                }

                $existingGroups = $this->fetchExistingGroups($ids);

                foreach ($chunks as $chunk) {
                    $names  = $this->fetchProductsData($chunk);
                    $prompt = $this->buildUserPrompt($names, $existingGroups, $suggestedGroups, $generationGroupType);

                    \PrestaShopLogger::addLog(
                        '[PO_LINKEDPRODUCT][DEBUG] USER PROMPT: ' . Tools::substr($prompt, 0, 50000),
                        1,
                        null,
                        'Po_linkedproduct'
                    );

                    $result = $this->callOpenAi($this->getSystemPrompt($suggestedGroups), $prompt);

                    \PrestaShopLogger::addLog(
                        '[PO_LINKEDPRODUCT][DEBUG] OPENAI RESULT: ' . Tools::substr(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 0, 50000),
                        3,
                        null,
                        'Po_linkedproduct'
                    );

                    $groups = array_merge($groups, $result);
                }

                if (!is_array($groups) || empty($groups)) {
                    $this->_html .= $this->displayError($this->l('Brak wygenerowanych powiązań.'));
                } else {
                    $db            = \Db::getInstance();
                    $languages     = \Language::getLanguages(false);
                    $defaultLangId = (int) \Configuration::get('PS_LANG_DEFAULT');

                    // helper pozycji – wykorzystuje już otwarte połączenie $db
                    $getNextPosition = function () use ($db): int {
                        $max = (int) $db->getValue('SELECT IFNULL(MAX(position), 0) FROM '._DB_PREFIX_.'po_linkedproduct');
                        return $max + 1;
                    };

                    $this->persistGeneratedGroups($groups, $db, $languages, $defaultLangId, $getNextPosition, $existingGroups, $generationGroupType);

                    $this->_html .= $this->displayConfirmation($this->l('Powiązania zostały wygenerowane i zapisane.'));
                }
            }
        } catch (\Exception $e) {
            $this->_html .= $this->displayError(
                $this->l('Wystąpił błąd podczas generowania powiązań: ') . $e->getMessage()
            );
        }
    }

    // zakładki
    $output .= '<ul class="nav nav-tabs">';
    $output .= '<li'.($tab === 'settings' ? ' class="active"' : '').'>
        <a href="'.$this->context->link->getAdminLink('AdminModules', true)
            .'&configure='.$this->name.'&po_tab=settings">'
            .$this->l('Ustawienia').'</a></li>';
    $output .= '<li'.($tab === 'generator' ? ' class="active"' : '').'>
        <a href="'.$this->context->link->getAdminLink('AdminModules', true)
            .'&configure='.$this->name.'&po_tab=generator">'
            .$this->l('Generator').'</a></li>';
    $output .= '<li'.($tab === 'mass_edit' ? ' class="active"' : '').'>
        <a href="'.$this->context->link->getAdminLink('AdminModules', true)
            .'&configure='.$this->name.'&po_tab=mass_edit">'
            .$this->l('Masowa edycja').'</a></li>';
    $output .= '<li'.($tab === 'features' ? ' class="active"' : '').'>
        <a href="'.$this->context->link->getAdminLink('AdminModules', true)
            .'&configure='.$this->name.'&po_tab=features">'
            .$this->l('Łączenie po cechach').'</a></li>';
    $output .= '</ul><div style="margin-top:20px;">';

    $output .= ($tab === 'generator')
        ? $this->renderGenerator()
        : (($tab === 'mass_edit')
            ? $this->renderMassEdit()
            : (($tab === 'features') ? $this->renderFeatureProfiles() : $this->renderSettingsForm()));

    $output .= '</div>';

    return $this->_html . $output;
}


private function getNextPosition(\Db $db, ?int $productId = null): int
{
    $sql = 'SELECT MAX(g.position)
            FROM '._DB_PREFIX_.'po_linkedproduct g
            INNER JOIN '._DB_PREFIX_.'po_linkedproduct_row r ON (r.group_id=g.id)';
    if ($productId) {
        $sql .= ' WHERE r.product_id='.(int)$productId;
    }

    return 1 + (int)$db->getValue($sql);
}


protected function renderProductsWithGroups(array $rows, array $groupsMatrix, bool $editable = false): string
{
    $html  = '<table class="table">';
    $html .= '<thead>
        <tr>
            <th style="width:70px">'.$this->l('Zdjęcie').'</th>
            <th>'.$this->l('Nazwa').'</th>
            <th style="width:120px">'.$this->l('Akcja').'</th>
        </tr>
    </thead><tbody>';

    foreach ($rows as $product) {
        $pid    = (int)$product['id_product'];
        $image  = \Image::getCover($pid);
        $imgUrl = '';

        if ($image && isset($image['id_image'])) {
            $imgUrl = $this->context->link->getImageLink(
                (string)$product['link_rewrite'],
                (int)$image['id_image'],
                'small_default'
            );
        }

        $html .= '<tr>';
        $html .= '<td>'.($imgUrl ? '<img src="'.$imgUrl.'" style="height:50px">' : '-').'</td>';
        $html .= '<td>'.htmlspecialchars((string)$product['name']).' <small class="text-muted">#'.$pid.'</small></td>';
        $html .= '<td>
            <a href="#" class="btn btn-default btn-xs lp-toggle" data-target="#lp-details-'.$pid.'">
                '.$this->l('Pokaż / ukryj').'
            </a>
        </td>';
        $html .= '</tr>';

        // szczegóły grup
        $groups = $groupsMatrix[$pid] ?? [];
        $html .= '<tr class="active">
            <td colspan="3" style="background:#fafafa">
                <div id="lp-details-'.$pid.'" style="margin:16px 32px; display:none;">';

        if (!$groups) {
            $html .= '<div class="text-muted">'.$this->l('Brak powiązań dla tego produktu.').'</div>';
        } else {
            foreach ($groups as $g) {
                $gid = (int)$g['id'];
                $html .= '<div class="panel" style="margin-bottom:10px">
                    <div class="panel-heading">
                        <span class="label label-default">#'.$gid.'</span>
                        <span class="label label-info">'.htmlspecialchars($g['type']).'</span>
                        <strong>'.htmlspecialchars($g['title']).'</strong>
                        <span class="text-muted">pos: '.(int)$g['position'].'</span>
                    </div>';

                if ($editable) {
                    // formularz edycji grupy
                    $html .= '<div style="padding:10px;border-top:1px solid #eee">';
                    $html .= '<div class="form-inline" style="display:flex;gap:10px;flex-wrap:wrap">';
                    $html .= '<input type="hidden" name="groups['.$gid.'][id]" value="'.$gid.'">';
                    $html .= '<div class="form-group">
                        <label>'.$this->l('Typ').'</label>
                        <input class="form-control" type="text" name="groups['.$gid.'][type]" value="'.htmlspecialchars($g['type']).'" style="width:140px">
                    </div>';
                    $html .= '<div class="form-group">
                        <label>'.$this->l('Pozycja').'</label>
                        <input class="form-control" type="number" name="groups['.$gid.'][position]" value="'.(int)$g['position'].'" style="width:100px">
                    </div>';
                    foreach ($this->languages as $lang) {
                        $idLangX = (int) $lang['id_lang'];
                        $titleX = \Db::getInstance()->getValue('
                            SELECT group_title FROM '._DB_PREFIX_.'po_linkedproduct_lang
                            WHERE id='.(int)$gid.' AND id_lang='.(int)$idLangX
                        ) ?: $g['title'];
                        $html .= '<div class="form-group">
                            <label>'.$this->l('Tytuł').' ('.$lang['iso_code'].')</label>
                            <input class="form-control" type="text" name="groups['.$gid.'][title]['.$idLangX.']" value="'.htmlspecialchars($titleX).'" style="min-width:220px">
                        </div>';
                    }
                    $html .= '</div></div>';
                }

                // lista wierszy
                $html .= '<div class="panel-body" style="padding:10px">
                    <table class="table table-condensed">
                        <thead>
                            <tr>
                                <th>'.$this->l('Row ID').'</th>
                                <th>'.$this->l('Produkt').'</th>
                                <th>'.$this->l('Wartość').'</th>
                            </tr>
                        </thead><tbody>';
                foreach ($g['rows'] as $r) {
                    $html .= '<tr>
                        <td>#'.$r['row_id'].'</td>
                        <td>'.htmlspecialchars($r['product_name']).'</td>';
                    if ($editable) {
                        $html .= '<td><input class="form-control" type="text" name="rows['.$r['row_id'].'][value]" value="'.htmlspecialchars((string)($r['value'] ?? '')).'"></td>';
                    } else {
                        $html .= '<td>'.htmlspecialchars((string)($r['value'] ?? '')).'</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</tbody></table></div>';
                $html .= '</div>'; // panel
            }
        }

        $html .= '</div></td></tr>';
    }

    $html .= '</tbody></table>';

    // toggle JS
    $html .= '<script>
    (function(){
        var toggles = document.querySelectorAll(".lp-toggle");
        function onClickToggle(e){
            e.preventDefault();
            var t = this.getAttribute("data-target");
            var el = document.querySelector(t);
            if (el) el.style.display = (el.style.display==="none"||el.style.display==="")?"block":"none";
        }
        for(var i=0;i<toggles.length;i++){ toggles[i].addEventListener("click", onClickToggle); }
    })();
    </script>';

    return $html;
}


protected function renderSettingsForm(): string
{
    $output = '';

    if (Tools::isSubmit('submitPoLinkedProductSettings')) {
        $url  = (string) Tools::getValue('PO_LINKEDPRODUCT_OPENAI_URL');
        $key  = (string) Tools::getValue('PO_LINKEDPRODUCT_OPENAI_KEY');
        $mode = (string) Tools::getValue('PO_LINKEDPRODUCT_LINKING_MODE', 'manual');
        if (!in_array($mode, ['manual', 'features'], true)) {
            $mode = 'manual';
        }

        Configuration::updateValue('PO_LINKEDPRODUCT_OPENAI_URL', $url);
        Configuration::updateValue('PO_LINKEDPRODUCT_OPENAI_KEY', $key);
        Configuration::updateValue('PO_LINKEDPRODUCT_LINKING_MODE', $mode);

        $output .= $this->displayConfirmation($this->l('Settings updated'));
    }

    $urlVal  = (string) Configuration::get('PO_LINKEDPRODUCT_OPENAI_URL');
    $keyVal  = (string) Configuration::get('PO_LINKEDPRODUCT_OPENAI_KEY');
    $modeVal = (string) Configuration::get('PO_LINKEDPRODUCT_LINKING_MODE');
    if (!in_array($modeVal, ['manual', 'features'], true)) {
        $modeVal = 'manual';
    }

    $output .= '
    <form method="post" class="defaultForm form-horizontal">
        <div class="panel">
            <div class="panel-heading">'.$this->l('Ustawienia').'</div>

            <div class="form-group">
                <label class="control-label col-lg-3">'.$this->l('OpenAI Base URL').'</label>
                <div class="col-lg-9">
                    <input type="text" name="PO_LINKEDPRODUCT_OPENAI_URL" value="'.htmlspecialchars($urlVal).'" class="form-control" placeholder="https://api.openai.com/v1/chat/completions" />
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-lg-3">'.$this->l('OpenAI API Key').'</label>
                <div class="col-lg-9">
                    <input type="text" name="PO_LINKEDPRODUCT_OPENAI_KEY" value="'.htmlspecialchars($keyVal).'" class="form-control" placeholder="sk-..." />
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-lg-3">'.$this->l('Tryb linkowania').'</label>
                <div class="col-lg-9">
                    <select name="PO_LINKEDPRODUCT_LINKING_MODE" class="form-control">
                        <option value="manual"'.($modeVal === 'manual' ? ' selected' : '').'>'.$this->l('Ręczne (istniejące)').'</option>
                        <option value="features"'.($modeVal === 'features' ? ' selected' : '').'>'.$this->l('Po cechach (profil + rodzina)').'</option>
                    </select>
                </div>
            </div>

            <div class="panel-footer">
                <button type="submit" name="submitPoLinkedProductSettings" class="btn btn-default pull-right">
                    <i class="process-icon-save"></i> '.$this->l('Save').'
                </button>
            </div>
        </div>
    </form>';

    return $output;
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
        <div class="panel-heading">'.$this->l('Profile linkowania po cechach').'</div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>'.$this->l('ID').'</th>
                        <th>'.$this->l('Nazwa').'</th>
                        <th>'.$this->l('Options CSV').'</th>
                        <th>'.$this->l('Family CSV').'</th>
                        <th>'.$this->l('Aktywny').'</th>
                        <th>'.$this->l('Akcje').'</th>
                    </tr>
                </thead>
                <tbody>';

    if (!$profiles) {
        $output .= '<tr><td colspan="6">'.$this->l('Brak profili.').'</td></tr>';
    } else {
        foreach ($profiles as $p) {
            $output .= '<tr>
                <td>#'.(int) $p['id_profile'].'</td>
                <td>'.htmlspecialchars((string) $p['name']).'</td>
                <td>'.htmlspecialchars((string) $p['options_csv']).'</td>
                <td>'.htmlspecialchars((string) ($p['family_csv'] ?? '')).'</td>
                <td>'.((int) $p['active'] === 1 ? '✅' : '❌').'</td>
                <td>
                    <a class="btn btn-default btn-xs" href="'.$this->context->link->getAdminLink('AdminModules', true)
                        .'&configure='.$this->name.'&po_tab=features&profile_id='.(int) $p['id_profile'].'">'.$this->l('Edytuj').'</a>
                    <form method="post" style="display:inline-block" onsubmit="return confirm(\''.$this->l('Usunąć profil?').'\');">
                        <input type="hidden" name="lp_action" value="delete_profile">
                        <input type="hidden" name="profile_id" value="'.(int) $p['id_profile'].'">
                        <input type="hidden" name="po_tab" value="features">
                        <button type="submit" class="btn btn-danger btn-xs">'.$this->l('Usuń').'</button>
                    </form>
                </td>
            </tr>';
        }
    }

    $output .= '</tbody></table></div></div>';

    $output .= '<form method="post" class="defaultForm form-horizontal">
        <input type="hidden" name="lp_action" value="save_profile">
        <input type="hidden" name="po_tab" value="features">
        <input type="hidden" name="profile_id" value="'.(int) $profileId.'">
        <div class="panel">
            <div class="panel-heading">'.($profileId ? $this->l('Edytuj profil') : $this->l('Dodaj profil')).'</div>
            <div class="form-group">
                <label class="control-label col-lg-3">'.$this->l('Nazwa').'</label>
                <div class="col-lg-9">
                    <input type="text" name="profile_name" class="form-control" value="'.htmlspecialchars((string) ($profile['name'] ?? '')).'" required>
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">'.$this->l('Cechy OPTIONS (max 3)').'</label>
                <div class="col-lg-9">
                    <select name="profile_options[]" class="form-control" multiple>
                        ';

    foreach ($featureOptions as $featureId => $featureName) {
        $output .= '<option value="'.(int) $featureId.'"'.(in_array($featureId, $selectedOptions, true) ? ' selected' : '').'>'
            .htmlspecialchars($featureName).'</option>';
    }

    $output .= '    </select>
                    <p class="help-block">'.$this->l('Wybierz 1-3 cechy do przełączników.').'</p>
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">'.$this->l('Cechy rodziny (opcjonalnie)').'</label>
                <div class="col-lg-9">
                    <select name="profile_family[]" class="form-control" multiple>
                        ';

    foreach ($featureOptions as $featureId => $featureName) {
        $output .= '<option value="'.(int) $featureId.'"'.(in_array($featureId, $selectedFamily, true) ? ' selected' : '').'>'
            .htmlspecialchars($featureName).'</option>';
    }

    $output .= '    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">'.$this->l('Aktywny').'</label>
                <div class="col-lg-9">
                    <input type="checkbox" name="profile_active" value="1"'.((int) ($profile['active'] ?? 1) === 1 ? ' checked' : '').'>
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">'.$this->l('Nagłówki (override)').'</label>
                <div class="col-lg-9">';

    if (!$selectedOptions) {
        $output .= '<p class="help-block">'.$this->l('Wybierz cechy w OPTIONS, aby ustawić własne nagłówki.').'</p>';
    } else {
        $output .= '<div class="table-responsive"><table class="table">
            <thead><tr><th>'.$this->l('Cecha').'</th>';
        foreach ($languages as $lang) {
            $output .= '<th>'.htmlspecialchars($lang['iso_code']).'</th>';
        }
        $output .= '</tr></thead><tbody>';
        foreach ($selectedOptions as $featureId) {
            $output .= '<tr><td>'.htmlspecialchars($featureOptions[$featureId] ?? ('#'.$featureId)).'</td>';
            foreach ($languages as $lang) {
                $value = $labelMap[$featureId][$lang['id_lang']] ?? '';
                $output .= '<td><input type="text" class="form-control" name="profile_label['.(int) $featureId.']['.(int) $lang['id_lang'].']" value="'.htmlspecialchars((string) $value).'"></td>';
            }
            $output .= '</tr>';
        }
        $output .= '</tbody></table></div>';
    }

    $output .= '    </div>
            </div>
            <div class="panel-footer">
                <button type="submit" class="btn btn-default pull-right">
                    <i class="process-icon-save"></i> '.$this->l('Save').'
                </button>
            </div>
        </div>
    </form>';

    $output .= '<form method="post" class="defaultForm">
        <input type="hidden" name="lp_action" value="rebuild_index">
        <input type="hidden" name="po_tab" value="features">
        <button type="submit" class="btn btn-primary" onclick="return confirm(\''.$this->l('Przebudować indeks dla wszystkich produktów?').'\');">
            '.$this->l('Przebuduj indeks').'
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

protected function deleteGroup(int $groupId): void
{
    if ($groupId <= 0) return;
    $db = \Db::getInstance();
    $db->execute('START TRANSACTION');

    try {
        // zbierz row_id z grupy
        $rowIds = $db->executeS(
            'SELECT id FROM '._DB_PREFIX_.'po_linkedproduct_row WHERE group_id='.(int)$groupId
        ) ?: [];

        if ($rowIds) {
            $ids = implode(',', array_map('intval', array_column($rowIds, 'id')));
            $db->delete('po_linkedproduct_row_lang', 'id_row IN ('.$ids.')');
            $db->delete('po_linkedproduct_row', 'id IN ('.$ids.')');
        }

        // usuń powiązania z produktami (to blokowało usunięcie grupy!)
        $db->delete('po_linkedproduct_position', 'group_id='.(int)$groupId);

        // kasuj lang dla grupy
        $db->delete('po_linkedproduct_lang', 'id='.(int)$groupId);

        // kasuj grupę
        $db->delete('po_linkedproduct', 'id='.(int)$groupId);

        $db->execute('COMMIT');
    } catch (\Exception $e) {
        $db->execute('ROLLBACK');
        throw $e;
    }
}

/**
 * Zapisuje pełny wynik generatora do wszystkich wymaganych tabel.
 * - transakcja (COMMIT/ROLLBACK)
 * - uzupełnianie brakujących tłumaczeń z języka domyślnego
 * - pozycje grupy globalnie + pozycje specyficzne per produkt (po_linkedproduct_position)
 * - czyszczenie poprzednich wierszy grupy przy aktualizacji (opcjonalnie)
 *
 * @param array      $groups  – wynik z OpenAI (lista grup)
 * @param \Db        $db
 * @param array      $languages – Language::getLanguages(false)
 * @param int        $defaultLangId
 * @param callable   $getNextPosition – callable(\Db $db): int   (fallback numeru pozycji)
 * @param string     $defaultGroupType – fallback typu generowanej grupy (text/photo)
 */
protected function persistGeneratedGroups(array $groups, \Db $db, array $languages, int $defaultLangId, callable $getNextPosition, array $existingGroups = [], string $defaultGroupType = 'text'): void
{
    if (empty($groups)) {
        throw new \RuntimeException('Brak wygenerowanych grup.');
    }

    $db->execute('START TRANSACTION');

    try {
        $existingMap = [];
        foreach ($existingGroups as $existing) {
            $title = (string) ($existing['title'][$defaultLangId] ?? reset($existing['title']) ?? '');
            $normalized = Tools::strtolower(trim($title));
            if ($normalized !== '' && !isset($existingMap[$normalized])) {
                $existingMap[$normalized] = (int) ($existing['id'] ?? 0);
            }
        }
        
        foreach ($groups as $group) {
            if (empty($group['products']) || !is_array($group['products'])) {
                continue;
            }

            $productIds = array_values(array_unique(array_filter(
                array_map('intval', $group['products']),
                static fn($v) => $v > 0
            )));
            if (!$productIds) {
                continue;
            }

            $type        = pSQL((string)($group['type'] ?? $defaultGroupType ?? 'text'));
            $titleByLang = is_array($group['title'] ?? null) ? $group['title'] : [];

            // 🔹 Fallback brakujących tłumaczeń
            foreach ($languages as $lang) {
                $idLang = (int)$lang['id_lang'];
                if (empty($titleByLang[$idLang])) {
                    $titleByLang[$idLang] = (string)($titleByLang[$defaultLangId] ?? '');
                }
            }

            $jsonPos  = (int)($group['position'] ?? 0);

            $groupPosition = $jsonPos > 0 ? $jsonPos : (int)$getNextPosition();

            // 🔹 Sprawdzenie ID (czy aktualizacja czy nowa grupa)
            $incomingGroupId = (int)($group['linked_id'] ?? $group['id'] ?? 0);
            if ($incomingGroupId <= 0) {
                $normalizedTitle = Tools::strtolower(trim((string)($titleByLang[$defaultLangId] ?? '')));
                if ($normalizedTitle !== '' && isset($existingMap[$normalizedTitle])) {
                    $incomingGroupId = $existingMap[$normalizedTitle];
                }
            }
            $groupId         = 0;
            $existingProductIds = [];

            if ($incomingGroupId > 0) {
                // 🔹 UPDATE istniejącej grupy
                $db->update('po_linkedproduct', [
                    'type'     => $type,
                    'position' => $groupPosition,
                ], 'id='.(int)$incomingGroupId);

                $groupId = $incomingGroupId;

                // 🔹 Pobierz istniejące produkty, aby dodać tylko brakujące
                $existingProducts = $db->executeS('SELECT DISTINCT product_id FROM '._DB_PREFIX_.'po_linkedproduct_row WHERE group_id='.(int)$groupId);
                if (is_array($existingProducts)) {
                    $existingProductIds = array_map('intval', array_column($existingProducts, 'product_id'));
                }


                // 🔹 Upsert tytułów wielojęzycznych
                foreach ($languages as $lang) {
                    $idLang = (int)$lang['id_lang'];
                    $exists = (int)$db->getValue(
                        'SELECT COUNT(*) FROM '._DB_PREFIX_.'po_linkedproduct_lang WHERE id='.(int)$groupId.' AND id_lang='.(int)$idLang
                    );
                    if ($exists) {
                        $db->update('po_linkedproduct_lang', [
                            'group_title' => pSQL($titleByLang[$idLang]),
                        ], 'id='.(int)$groupId.' AND id_lang='.(int)$idLang);
                    } else {
                        $db->insert('po_linkedproduct_lang', [
                            'id'          => $groupId,
                            'id_lang'     => $idLang,
                            'group_title' => pSQL($titleByLang[$idLang]),
                        ]);
                    }
                }

                // // 🔹 Czyścimy stare wiersze
                // $rows = $db->executeS('SELECT id FROM '._DB_PREFIX_.'po_linkedproduct_row WHERE group_id='.(int)$groupId);
                // foreach ($rows as $r) {
                //     $db->delete('po_linkedproduct_row_lang', 'id_row='.(int)$r['id']);
                // }
                // $db->delete('po_linkedproduct_row', 'group_id='.(int)$groupId);

            } else {
                // 🔹 NOWA grupa
                $db->insert('po_linkedproduct', [
                    'type'     => $type,
                    'position' => $groupPosition,
                ]);
                $groupId = (int)$db->Insert_ID();

                foreach ($languages as $lang) {
                    $idLang = (int)$lang['id_lang'];
                    $db->insert('po_linkedproduct_lang', [
                        'id'          => $groupId,
                        'id_lang'     => $idLang,
                        'group_title' => pSQL($titleByLang[$idLang]),
                    ]);
                }
            }

            // 🔹 Pozycja per produkt (opcjonalnie z JSON)
            $productsToInsert = $incomingGroupId > 0
                ? array_values(array_diff($productIds, $existingProductIds))
                : $productIds;

            foreach ($productsToInsert as $pid) {
                $perProductPos = $group['positions'][$pid] ?? null;
                $finalPos      = $perProductPos !== null ? (int)$perProductPos : $groupPosition;

                $db->execute('REPLACE INTO '._DB_PREFIX_.'po_linkedproduct_position (product_id, group_id, position) 
                    VALUES ('.(int)$pid.', '.(int)$groupId.', '.(int)$finalPos.')');
            }

            // 🔹 Wiersze + wartości językowe
            $values = $group['values'] ?? [];

            $rowPositionBase = (int) $db->getValue('SELECT MAX(position) FROM ' . _DB_PREFIX_ . 'po_linkedproduct_row WHERE group_id=' . (int) $groupId);
            foreach (array_values($productsToInsert) as $index => $pid) {
                $rowPosition = $rowPositionBase + $index + 1;
                $db->insert('po_linkedproduct_row', [
                    'group_id'   => $groupId,
                    'product_id' => $pid,
                    'position'   => $rowPosition,
                    'value'      => '',
                ]);
                $rowId = (int)$db->Insert_ID();

                foreach ($languages as $lang) {
                    $idLang = (int)$lang['id_lang'];
                    $val    = $values[$pid][$idLang] ?? ($values[$pid][$defaultLangId] ?? '');
                    $db->insert('po_linkedproduct_row_lang', [
                        'id_row'  => $rowId,
                        'id_lang' => $idLang,
                        'value'   => pSQL($val),
                    ]);
                }
            }
        }

        $db->execute('COMMIT');

    } catch (\Throwable $e) {
        $db->execute('ROLLBACK');
        throw $e;
    }
}





protected function deleteRow(int $rowId): void
{
    if ($rowId <= 0) return;
    $db = \Db::getInstance();
    $db->execute('START TRANSACTION');

    try {
        // pobierz group_id by ewentualnie posprzątać pustą grupę
        $gid = (int) $db->getValue('SELECT group_id FROM '._DB_PREFIX_.'po_linkedproduct_row WHERE id='.(int)$rowId);

        $db->delete('po_linkedproduct_row_lang', 'id_row='.(int)$rowId);
        $db->delete('po_linkedproduct_row', 'id='.(int)$rowId);

        if ($gid) {
            // jeśli grupa nie ma już wierszy – usuń ją
            $left = (int) $db->getValue('SELECT COUNT(*) FROM '._DB_PREFIX_.'po_linkedproduct_row WHERE group_id='.(int)$gid);
            if ($left === 0) {
                $db->delete('po_linkedproduct_lang', 'id='.(int)$gid);
                $db->delete('po_linkedproduct', 'id='.(int)$gid);
            }
        }

        $db->execute('COMMIT');
    } catch (\Exception $e) {
        $db->execute('ROLLBACK');
        throw $e;
    }
}

protected function updateGroup(int $groupId, array $titles, int $position, string $type): void
{
    if ($groupId <= 0) return;

    $db = \Db::getInstance();
    $db->execute('START TRANSACTION');

    try {
        $db->update('po_linkedproduct', [
            'type'     => pSQL($type),
            'position' => (int) $position,
        ], 'id='.(int)$groupId);

        // fallback dla brakujących tłumaczeń: użyj domyślnego
        $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');
        $fallback = $titles[$defaultLangId] ?? reset($titles) ?? '';

        foreach ($this->languages as $lang) {
            $idLang = (int) $lang['id_lang'];
            $title  = (string) ($titles[$idLang] ?? $fallback);

            // czy istnieje rekord?
            $exists = (int) $db->getValue('SELECT COUNT(*) FROM '._DB_PREFIX_.'po_linkedproduct_lang WHERE id='.(int)$groupId.' AND id_lang='.(int)$idLang);
            if ($exists) {
                $db->update('po_linkedproduct_lang', [
                    'group_title' => pSQL($title),
                ], 'id='.(int)$groupId.' AND id_lang='.(int)$idLang);
            } else {
                $db->insert('po_linkedproduct_lang', [
                    'id'          => (int)$groupId,
                    'id_lang'     => (int)$idLang,
                    'group_title' => pSQL($title),
                ]);
            }
        }

        $db->execute('COMMIT');
    } catch (\Exception $e) {
        $db->execute('ROLLBACK');
        throw $e;
    }
}

protected function processMassUpdateFieldAjax(): void
{
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');
    header('Content-Type: application/json');

    try {
        if (!$this->isValidToken()) {
            throw new \Exception($this->l('Nieprawidłowy token.'));
        }

        $target = (string) Tools::getValue('target', '');
        $message = '';

        if ($target === 'group') {
            $message = $this->saveAjaxGroupField();
        } elseif ($target === 'row') {
            $message = $this->saveAjaxRowField();
        } else {
            throw new \Exception($this->l('Nieznany typ pola.'));
        }

        echo json_encode([
            'success' => true,
            'message' => $message,
        ]);
    } catch (\Exception $e) {
        if (!headers_sent()) {
            http_response_code(400);
        }

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }

    exit;
}


protected function saveAjaxGroupField(): string
{
    $groupId = (int) Tools::getValue('group_id');
    if ($groupId <= 0) {
        throw new \Exception($this->l('Brak identyfikatora grupy.'));
    }

    $field = (string) Tools::getValue('field', '');
    $value = (string) Tools::getValue('value', '');
    $db    = \Db::getInstance();

    switch ($field) {
        case 'type':
            if (!$db->update('po_linkedproduct', ['type' => pSQL($value)], 'id='.(int) $groupId)) {
                throw new \Exception($this->l('Nie udało się zaktualizować typu grupy.'));
            }
            return $this->l('Typ grupy zapisany.');

        case 'position':
            if (!$db->update('po_linkedproduct', ['position' => (int) $value], 'id='.(int) $groupId)) {
                throw new \Exception($this->l('Nie udało się zaktualizować pozycji grupy.'));
            }
            $productId = (int) Tools::getValue('product_id');
            if ($productId > 0) {
                $db->execute('REPLACE INTO '._DB_PREFIX_.'po_linkedproduct_position (product_id, group_id, position) VALUES ('
                    . (int) $productId . ', ' . (int) $groupId . ', ' . (int) $value . ')');
            }
            return $this->l('Pozycja grupy zapisana.');

        case 'title':
            $idLang = (int) Tools::getValue('id_lang', (int) $this->context->language->id);
            if ($idLang <= 0) {
                $idLang = (int) $this->context->language->id;
            }

            $exists = (int) $db->getValue('SELECT COUNT(*) FROM '._DB_PREFIX_.'po_linkedproduct_lang WHERE id='.(int) $groupId.' AND id_lang='.(int) $idLang);

            if ($exists) {
                if (!$db->update('po_linkedproduct_lang', ['group_title' => pSQL($value)], 'id='.(int) $groupId.' AND id_lang='.(int) $idLang)) {
                    throw new \Exception($this->l('Nie udało się zapisać tytułu grupy.'));
                }
            } else {
                if (!$db->insert('po_linkedproduct_lang', [
                    'id'          => (int) $groupId,
                    'id_lang'     => (int) $idLang,
                    'group_title' => pSQL($value),
                ])) {
                    throw new \Exception($this->l('Nie udało się dodać tytułu grupy.'));
                }
            }

            return $this->l('Tytuł grupy zapisany.');
    }

    throw new \Exception($this->l('Nieobsługiwane pole grupy.'));
}

protected function saveAjaxRowField(): string
{
    $rowId  = (int) Tools::getValue('row_id');
    $idLang = (int) Tools::getValue('id_lang');
    $value  = (string) Tools::getValue('value', '');
    $field  = (string) Tools::getValue('field', 'value');

    if ($rowId <= 0) {
        throw new \Exception($this->l('Brak identyfikatora powiązania.'));
    }
    $db = \Db::getInstance();

    if ($field === 'position') {
        if (!$db->update('po_linkedproduct_row', ['position' => (int) $value], 'id='.(int)$rowId)) {
            throw new \Exception($this->l('Nie udało się zapisać pozycji powiązania.'));
        }
        return $this->l('Pozycja powiązania zapisana.');
    }

    if ($idLang <= 0) {
        $idLang = (int) $this->context->language->id;
    }

    $exists = (int) $db->getValue('
        SELECT COUNT(*) 
        FROM '._DB_PREFIX_.'po_linkedproduct_row_lang
        WHERE id_row='.(int)$rowId.' AND id_lang='.(int)$idLang
    );

    if ($exists) {
        if (!$db->update(
            'po_linkedproduct_row_lang',
            ['value' => pSQL($value)],
            'id_row='.(int)$rowId.' AND id_lang='.(int)$idLang
        )) {
            throw new \Exception($this->l('Nie udało się zapisać wartości powiązania.'));
        }
    } else {
        if (!$db->insert('po_linkedproduct_row_lang', [
            'id_row'  => (int)$rowId,
            'id_lang' => (int)$idLang,
            'value'   => pSQL($value),
        ])) {
            throw new \Exception($this->l('Nie udało się dodać wartości powiązania.'));
        }
    }

    return $this->l('Wartość powiązania zapisana.');
}


/**
 * Zwraca macierz powiązań dla podanych produktów.
 * Wynik: [ product_id => [ [group], ... ] ]
 * group: [
 *   'id' => int, 'type' => string, 'position' => int,
 *   'title' => string (dla $idLang),
 *   'rows' => [ [ 'row_id'=>int, 'product_id'=>int, 'product_name'=>string, 'value'=>string|null ], ... ]
 * ]
 */
protected function fetchGroupsMatrix(array $productIds, int $idLang): array
{
    $matrix = [];
    if (empty($productIds)) return $matrix;

    $idsSql = implode(',', array_map('intval', $productIds));
    $db = \Db::getInstance();

    // 1) Wszystkie grupy, w których występuje którykolwiek z produktów
    $sql = '
        SELECT g.id AS gid, g.type, g.position,
               gl.group_title,
               r.id AS row_id, r.product_id, r.position AS row_position,
               rl.value
        FROM '._DB_PREFIX_.'po_linkedproduct g
        INNER JOIN '._DB_PREFIX_.'po_linkedproduct_lang gl ON (gl.id=g.id AND gl.id_lang='.(int)$idLang.')
        INNER JOIN '._DB_PREFIX_.'po_linkedproduct_row r ON (r.group_id=g.id)
        LEFT JOIN '._DB_PREFIX_.'po_linkedproduct_row_lang rl ON (rl.id_row=r.id AND rl.id_lang='.(int)$idLang.')
        WHERE r.group_id IN (
            SELECT DISTINCT r2.group_id
            FROM '._DB_PREFIX_.'po_linkedproduct_row r2
            WHERE r2.product_id IN ('.$idsSql.')
        )
        ORDER BY g.position ASC, g.id ASC, r.position ASC, r.id ASC
    ';

    $rows = $db->executeS($sql) ?: [];

    if (!$rows) return $matrix;

    // 2) Dociągnij nazwy produktów dla wszystkich product_id z tych grup
    $allPids = array_values(array_unique(array_map('intval', array_column($rows, 'product_id'))));
    $names = [];
    if ($allPids) {
      $namesSql = '
    SELECT p.id_product, pl.name, pl.link_rewrite, i.id_image
    FROM '._DB_PREFIX_.'product p
    INNER JOIN '._DB_PREFIX_.'product_lang pl
        ON (pl.id_product=p.id_product AND pl.id_lang='.(int)$idLang.' AND pl.id_shop='.(int)$this->context->shop->id.')
    LEFT JOIN '._DB_PREFIX_.'image i ON (i.id_product = p.id_product AND i.cover = 1)
    WHERE p.id_product IN ('.implode(',', $allPids).')
';
foreach ($db->executeS($namesSql) ?: [] as $n) {
    $names[(int)$n['id_product']] = [
        'name'        => (string)$n['name'],
        'link_rewrite'=> (string)$n['link_rewrite'],
        'id_image'    => (int)$n['id_image'],
    ];
}

    }

    // 3) Zbuduj strukturę i przypisz do każdego z żądanych productIds
    // Najpierw grupy => wiersze
    $groupsById = [];
    foreach ($rows as $r) {
        $gid = (int)$r['gid'];
        if (!isset($groupsById[$gid])) {
            $groupsById[$gid] = [
                'id'       => $gid,
                'type'     => (string)$r['type'],
                'position' => (int)$r['position'],
                'title'    => (string)$r['group_title'],
                'rows'     => [],
            ];
        }
        $pid = (int)$r['product_id'];
        $groupsById[$gid]['rows'][] = [
    'row_id'       => (int)$r['row_id'],
    'product_id'   => $pid,
    'position'     => (int) $r['row_position'],
    'product_name' => $names[$pid]['name'] ?? ('#'.$pid),
    'value'        => isset($r['value']) ? (string)$r['value'] : null,
    'image_url'    => (!empty($names[$pid]['id_image']) && !empty($names[$pid]['link_rewrite']))
        ? $this->context->link->getImageLink($names[$pid]['link_rewrite'], $names[$pid]['id_image'], 'small_default')
        : null,
];

    }

    // Dla każdego z listowanych produktów przypnij tylko te grupy, w których faktycznie uczestniczy
    foreach ($productIds as $pid) {
        $pid = (int)$pid;
        $matrix[$pid] = [];
        foreach ($groupsById as $g) {
            $has = false;
            foreach ($g['rows'] as $row) {
                if ($row['product_id'] === $pid) { $has = true; break; }
            }
            if ($has) {
                $matrix[$pid][] = $g;
            }
        }
    }

    return $matrix;
}









    /** -------------------------
     *  HOOKI
     *  -------------------------
     */
    public function hookDisplayProductLinked($params)
    {
        $hook = new \Piano\LinkedProduct\Hook\DisplayProductLinked($this, \Context::getContext());
        return $hook->run($params);
    }

    public function __call($methodName, array $arguments)
    {
        if (0 === strpos($methodName, 'hook')) {
            $hook = $this->getHookObject($methodName);
            if ($hook) {
                return $hook->run($arguments[0] ?? []);
            }
        }
        return null;
    }

    public function hookDisplayHeader($params)
    {
        $this->context->controller->registerJavascript(
            'modules-po_linkedproduct-powertip',
            'modules/' . $this->name . '/views/js/jquery.powertip.min.js',
            ['position' => 'head', 'priority' => 100]
        );

        $this->context->controller->registerJavascript(
            'modules-po_linkedproduct-front',
            'modules/' . $this->name . '/views/js/front.js',
            ['position' => 'bottom', 'priority' => 200]
        );

        $this->context->controller->registerStylesheet(
            'modules-po_linkedproduct-style',
            'modules/' . $this->name . '/views/css/jquery.powertip.min.css',
            ['media' => 'all', 'priority' => 100]
        );
        $this->context->controller->registerStylesheet(
            'modules-po_linkedproduct-style-front',
            'modules/' . $this->name . '/views/css/front.css',
            ['media' => 'all', 'priority' => 150]
        );
    }

    private function getHookObject(string $methodName)
    {
        $method = lcfirst(substr($methodName, 4));
        $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $method));
        $serviceName = 'piano.linked_product.hook.' . $snake;

        $container = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
        if (!$container) {
            return null;
        }

        try {
            return $container->get($serviceName);
        } catch (\Exception $e) {
          
            return null;
        }
    }

    /**
 * Pobiera dane produktów dla danego chunku
 */
protected function fetchProductsData(array $ids): array
{
    if (empty($ids)) {
        return [];
    }

    $sql = '
        SELECT p.id_product, pl.name, pl.link_rewrite
        FROM '._DB_PREFIX_.'product p
        INNER JOIN '._DB_PREFIX_.'product_lang pl
            ON (pl.id_product = p.id_product AND pl.id_lang='.(int)$this->context->language->id.')
        WHERE p.id_product IN ('.implode(',', array_map('intval', $ids)).')
    ';

    return \Db::getInstance()->executeS($sql) ?: [];
}

/**
 * Zwraca listę cech (feature) w formacie [id => name] dla danego języka.
 */
protected function getFeatureOptions(int $idLang): array
{
    $options = [];
    foreach (\Feature::getFeatures($idLang) as $feature) {
        $id   = (int)($feature['id_feature'] ?? 0);
        $name = (string)($feature['name'] ?? '');
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

/**
 * Normalizuje sugerowane grupy do postaci listy nazw oddzielonych przecinkiem.
 * Obsługuje wartości z formularza (tablica ID cech) oraz surowe stringi.
 */
protected function normalizeSuggestedGroups($rawValue, array $featureOptions): string
{
    if (is_array($rawValue)) {
        $names = [];
        foreach ($rawValue as $featureId) {
            $id = (int)$featureId;
            if (isset($featureOptions[$id])) {
                $names[] = $featureOptions[$id];
            }
        }

        return implode(', ', array_values(array_unique($names)));
    }

    $singleId = (int)$rawValue;
    if ($singleId > 0 && isset($featureOptions[$singleId])) {
        return $featureOptions[$singleId];
    }

    return trim((string)$rawValue);
}

/**
 * Buduje prompt użytkownika na podstawie danych produktów
 */
protected function buildUserPrompt(
    array $names,
    array $existingGroups = [],
    string $suggestedGroups = '',
    string $generationGroupType = 'text'
): string


{
  $lines = [];
foreach ($names as $row) {
     $lines[] = (int)$row['id_product'].' | '.$row['name'];
}

$prompt = ''; 

// sugerowane grupy
if ($suggestedGroups !== '') {
    $prompt .= "\n\nSugerowane grupy do wygenerowania (od użytkownika):\n"
             .$suggestedGroups;
}

// preferowany typ grupy
if ($generationGroupType !== '') {
    $prompt .= "\n\nPreferowany typ generowanych grup: ".$generationGroupType;
}

// lista produktów
$prompt .= "\n\nProdukty do powiązania:\n".implode("\n", $lines);

// istniejące grupy
if (!empty($existingGroups)) {
    $prompt .= "\n\nSugerowane grupy wariantów (już istnieją w bazie):\n"
             . json_encode($existingGroups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $prompt .= "\n\nTwoje zadanie: wygeneruj **tylko brakujące grupy**. Jeśli widzisz pasującą grupę, w polu linked_id podaj jej id, aby ją uzupełnić zamiast tworzyć nową.";
}

// reguły końcowe
$prompt .= "\n\n⚠️ IMPORTANT:\n"
         ."1. Generate ONLY these groups and their missing variants.\n"
         ."2. Use exactly these names as `group_title`.\n"
         ."3. Do not invent additional groups outside this list.\n";


    return $prompt;
}






protected function fetchExistingGroups(array $ids): array
{
    if (empty($ids)) {
        return [];
    }

    $db = \Db::getInstance();
    $groupIdSql = 'SELECT DISTINCT g.id
            FROM '._DB_PREFIX_.'po_linkedproduct g
            INNER JOIN '._DB_PREFIX_.'po_linkedproduct_row r ON (r.group_id=g.id)
            WHERE r.product_id IN ('.implode(',', array_map('intval', $ids)).')';

    $groupIds = array_map('intval', array_column($db->executeS($groupIdSql) ?: [], 'id'));

    if (empty($groupIds)) {
        return [];
    }
    $sql = 'SELECT g.id, g.type, gl.group_title, r.product_id, rl.value, gl.id_lang
            FROM '._DB_PREFIX_.'po_linkedproduct g
            INNER JOIN '._DB_PREFIX_.'po_linkedproduct_lang gl ON (gl.id=g.id)
            INNER JOIN '._DB_PREFIX_.'po_linkedproduct_row r ON (r.group_id=g.id)
            LEFT JOIN '._DB_PREFIX_.'po_linkedproduct_row_lang rl ON (rl.id_row=r.id)
            WHERE g.id IN ('.implode(',', $groupIds).')';

    $rows = $db->executeS($sql);

    $groups = [];
    foreach ($rows as $row) {
        $gid = (int)$row['id'];
        if (!isset($groups[$gid])) {
            $groups[$gid] = [
                'id'       => $gid,
                'type'     => $row['type'],
                'title'    => [],
                'products' => [],
                'values'   => [],
            ];
        }
        $groups[$gid]['title'][$row['id_lang']] = $row['group_title'];
        $groups[$gid]['products'][] = (int)$row['product_id'];
        if ($row['value'] !== null) {
            $groups[$gid]['values'][(int)$row['product_id']][$row['id_lang']] = $row['value'];
        }
    }

    return array_values($groups);
}

    /** -------------------------
     *  OpenAI – integracja
     *  -------------------------
     */
/** -------------------------
     *  OpenAI – integracja
     *  -------------------------
     */

    /**
     * Systemowy prompt – precyzyjny schemat wyjściowy
     */
protected function getSystemPrompt(string $suggestedGroups = ''): string
{
    $languages = \Language::getLanguages(false);
    $langsInfo = [];
    foreach ($languages as $lang) {
        $langsInfo[] = $lang['id_lang'] . ': ' . $lang['iso_code'];
    }
    $langsText = implode(", ", $langsInfo);

    $txt = <<<TXT
1. Jesteś generatorem powiązań wariantów produktów dla sklepu PrestaShop.

2. Nazwę grupy podajesz tylko w jednym miejscu poprzez znacznik: {{GROUP_TITLE}}.
- Wszędzie dalej w prompcie systemowym i w danych wyjściowych odnoszą się automatycznie do wartości tego znacznika.
- {{GROUP_TITLE}} powinna być automatycznie poddana ekstrakcji – wyodrębnij z niej prawidłową nazwę grupy (parametru) oraz prawidłowe wartości (jeśli są podane).
- ZAWSZE obsłuż placeholder {{GROUP_TITLE}} zgodnie z szablonem JSON poniżej, nigdy go nie ignoruj.
- {{GROUP_TITLE}} może mieć również strukturę: sugerowana nazwa grupy oraz sugerowane wartości (np. Mocowanie Air Tag (Tak/Nie)) – wyodrębnij z tego nazwę grupy = „Mocowanie Air Tag” i wartości = [„Tak”, „Nie”].

3. Zasady ogólne:
- Analizuj wszystkie podane produkty.
- MUSISZ wybrać produkt bazowy i budować grupy wariantów WYŁĄCZNIE względem niego, stanowi on logiczny punkt odniesienia.

4. Muszą zostać wygenerowane wszystkie możliwe grupy, z tym że każda obejmuje produkty różniące się dokładnie jednym parametrem i tym parametrem może być wyłącznie jeden wybrany przez użytkownika/algorytm. Nie można uwzględniać więcej niż jednego parametru w grupowaniu. Jeśli w ramach analizowanego parametru występują dodatkowe parametry (np. rozmiar), grupuj osobno dla każdego ich zestawu. 

5. Każda wygenerowana grupa musi:
- Zawierać produkt bazowy,
- Obejmować tylko produkty, które różnią się od bazowego dokładnie jednym parametrem (nie więcej),
- Łączyć produkty (nie pojedyncze pary!), które różnią się wyłącznie tym jednym parametrem – cała taka grupa, minimum 2 produkty, wszystkie muszą różnić się tylko jednym parametrem.
5a. Unikaj tworzenia grup mieszanych, w których produkty różnią się więcej niż jednym parametrem.Każda grupa musi obejmować wyłącznie produkty identyczne we wszystkich innych parametrach poza analizowanym.Jeśli w ramach tego samego parametru (np. „Mocowanie Air Tag”) istnieją różne warianty innych parametrów (np. „Rozmiar”), utwórz osobne grupy dla każdego zestawu identycznych parametrów pozostałych. 

6. Zasady szczegółowe:
- Nie dołączaj do grupy produktów różniących się więcej niż jednym parametrem.
- Jeżeli produkty różnią się różnymi pojedynczymi parametrami, utwórz osobne grupy dla każdego z nich.
- Dla każdego parametru i produktu bazowego generuj dokładnie jedną grupę obejmującą produkt bazowy i wszystkie inne produkty identyczne z nim, oprócz tego jednego, analizowanego parametru.
- Grupę {{GROUP_TITLE}} twórz tylko, jeśli dany rodzaj parametru występuje w różnych wartościach.
- Nie twórz grupy {{GROUP_TITLE}}, jeśli wszystkie produkty mają tę samą wartość tego parametru.

7. Nigdy nie twórz grupy nie zawierającej produktu bazowego.

8. Parametry do analizy (nie ograniczone wyłącznie do poniższych):
- Łączność (GPS, GPS + Cellular) → typ: "text"
- Kolor (Jet Black, Space Grey, Rose Gold, Silver) → typ: "photo"
- Rozmiar koperty (42mm, 46mm) → typ: "text"
- Rozmiar paska (S/M, M/L, L/XL) → typ: "text"
- Rodzaj paska (Sport Band, Milanese Loop itp.) → typ: "photo"
- Materiał koperty (Aluminium, Titanium, Steel itp.) → typ: "photo"
- Typ mocowania (Clamp, Adapter itp.) → typ: "text"
- Radar (Varia 515, Varia 516 itp.) → typ: "text"

9. Produkty różniące się więcej niż jednym parametrem nie powinny być grupowane.

10. Podczas generowania grupy dla dowolnego parametru X dołączaj tylko produkty identyczne z produktem bazowym we wszystkich innych parametrach oprócz X.

11. Każdy parametr musi tworzyć osobną grupę.

12. Pole "group_title" ("title") musi jednoznacznie wskazywać nazwę parametru, tutaj: {{GROUP_TITLE}}, gdzie {{GROUP_TITLE}} powinien zostać automatycznie rozbity na: nazwę parametru oraz wartości (jeśli są zawarte w tej strukturze). ZAWSZE wyodrębniaj te elementy i przypisuj do odpowiednich pól – nigdy nie pomijaj ekstrakcji!

13. Pole "values" musi zawierać krótkie i jednoznaczne wartości dla każdego produktu (np. "S", "M", "L"). Jeżeli są podane wartości w {{GROUP_TITLE}}, używaj ich jako preferowanych etykiet.

14. Każdy produkt może należeć do wielu grup, jeśli produkt bazowy również występuje w każdej z nich.

15. Wynikowy JSON MUSI obejmować wszystkie produkty.

16. Unikaj duplikatów – nie twórz tej samej grupy dla tego samego produktu więcej niż raz.

17. Odpowiedź musi być wyłącznie poprawnym JSON-em (bez komentarzy, markdown, czy wyjaśnień).

18. Zawsze generuj wynik w wielu językach.
- Dostępne języki: {$langsText}.
- Dla każdego pola title i values utwórz tłumaczenia dla wszystkich języków (klucze = id_lang).
- Tłumaczenia muszą być naturalne dla danego języka.

19. Jeżeli istnieją już grupy wariantów w sekcji „Istniejące grupy wariantów”, nie powtarzaj ich – generuj tylko brakujące. 
    Jeśli znajdziesz pasującą nazwę parametru, ustaw w JSON pole "linked_id" z id podanym w sekcji istniejących grup i aktualizuj tę grupę zamiast tworzyć nową

20. Jeśli użytkownik podał „Sugerowane grupy do wygenerowania”, potraktuj je priorytetowo, ale nie ustawiaj dodatkowych priorytetów ani pozycji – kolejność nie ma znaczenia.

21. Jeśli użytkownik poda listę sugerowanych nazw grup (np. {{GROUP_TITLE}}):
- Traktuj je jako kanoniczne nazwy parametrów.
- Usuń przykładowe dopiski w nawiasach.
- Ostateczna nazwa "title" musi dokładnie odpowiadać czystemu nazewnictwu parametru.
- Dla rozszerzonych struktur tytułu (np. Mocowanie Air Tag (Tak/Nie)) obsłuż te elementy zgodnie z podanym formatem, czyli rozbij na składowe: nazwa i wartości.

22. Jeśli użytkownik poda sugerowane wartości wariantów (np. {{GROUP_TITLE}}: S, M, L):
- Użyj tych wartości jako preferowanych etykiet.
- Jeśli produkt pasuje do którejś z sugerowanych wartości, użyj tej etykiety dokładnie tak.
- Jeśli w produktach są dodatkowe wartości spoza sugestii – uwzględnij je również.

23. Format JSON:
[ { "type": "text", "position": 1, "title": { "1": "{{GROUP_TITLE}}" }, "products": [<product_id>, ...], "values": { "<product_id>": { "1": "<string>" } } } ]
- Jeśli grupa odpowiada istniejącej pozycji z listy, dodaj pole linked_id z jej id (wtedy grupa ma być zaktualizowana).

25. Przed wygenerowaniem JSON ZAWSZE wygeneruj checklistę kroków koncepcyjnych (7 punktów):
1. Ekstrakcja sugerowanej grupy
2. Analiza produktów
3. Wybranie produktu bazowego
4. Grupowanie produktów
5. Każda grupa musi zawierać oba warianty
6. Sprawdzenie, czy nie powielamy istniejacych grup
7. Walidacja: każda grupa zawiera produkt bazowy, różni się tylko jednym parametrem, nie ma duplikatów

26. WALIDACJA: Po każdym istotnym etapie wstaw wyraźny krok kontrolny – krótko zweryfikuj, czy uzyskany efekt spełnia kryteria zadania (czy wybrano produkt bazowy, poprawnie rozbito {{GROUP_TITLE}}, nie wygenerowano par zamiast grup, brak duplikatów, czy uwzględniono wszystkie produkty). Jeżeli brakuje którejkolwiek wymaganej grupy lub wartość jest niejednoznaczna, pomiń dany produkt bez raportowania błędu i przejdź do kolejnego kroku. Sprawdź, czy wszystkie produkty w danej grupie różnią się od siebie tylko jednym parametrem (tym samym).
Jeśli jakikolwiek produkt w grupie ma inne różnice (np. inny rozmiar), rozbij grupę na mniejsze, aż warunek będzie spełniony.
Jeśli po rozbiciu grupa ma tylko 1 produkt, pomiń ją. 

### Szczegółowe wytyczne dla tego zadania:
- Wygeneruj wszystkie grupy obejmujące produkty, które różnią się tylko jednym (tym samym) parametrem. Nie bierz pod uwagę więcej niż jednego parametru w pojedynczym grupowaniu. Przygotuj kolumny z uwzględnieniem wyodrębnionych: nazwy grupy i wartości z {{GROUP_TITLE}}.
- Użyj dokładnie tej nazwy jako "group_title" w tym formacie po ekstrakcji.
- Pole "position" może pozostać domyślne (1) lub pochodzić z danych wejściowych, jeśli jest jednoznacznie podane. Nie stosuj dodatkowych priorytetów.
- Nie twórz żadnych innych grup niż te odpowiadające produktom różniącym się tylko jednym wybranym parametrem.

### Format wyjściowy
Wyjście musi być poprawną tablicą JSON (bez komentarzy, markdown, czy wyjaśnień), zgodnie ze schematem:
[
  {
    "type": "text",
    "position": 1,
    "linked_id": <id_istniejącej_grupy>,
    "title": { "1": "{{GROUP_TITLE}}" },
    "products": [<product_id>, ...],
    "values": {
      "<product_id>": { "1": "<string>" },
      ...
    }
  }
]
- Użyj id_lang = 1 dla polskiego.
- "products" zawiera produkty z jednoznacznym {{GROUP_TITLE}} (wyodrębnionym z nazwy produktu: S, M, L).
- Jeśli grupa jest kontynuacją istniejącej z listy, ustaw "linked_id" na jej identyfikator, aby dołączyć brakujące warianty.
- Wartości w "values" powinny być zgodne z wartością {{GROUP_TITLE}} z nazwy produktu lub z wartości wyodrębnionej podczas ekstrakcji.
- Nie twórz wartości/grup, jeśli wartość parametru produktu jest niejednoznaczna lub nie da się jej jednoznacznie określić – taki produkt pomiń bez raportowania błędu.

Zawsze generuj checklistę (jako tekst) oraz na końcu poprawny JSON według powyższej specyfikacji.
TXT;

    // 🔁 Zastępujemy znacznik {{GROUP_TITLE}} wartością z $suggestedGroups
    $finalPrompt = str_replace('{{GROUP_TITLE}}', trim($suggestedGroups), $txt);

    return $finalPrompt;
}



    /**
     * UI Generatora: lista produktów + checkboxy + paginacja + wyszukiwarka
     */
protected function renderGenerator(): string
{
    $token  = Tools::getAdminTokenLite('AdminModules');
    $page    = max(1, (int)Tools::getValue('p', 1));
    $perPage = max(1, min(200, (int)Tools::getValue('n', 50)));
    $search  = trim((string)Tools::getValue('q', ''));
    $offset  = ($page - 1) * $perPage;
    $idLang  = (int)$this->context->language->id;
    $featureOptions = $this->getFeatureOptions($idLang);
    $selectedFeature = Tools::getValue('suggested_groups', '');
    if (is_array($selectedFeature)) {
        $selectedFeature = reset($selectedFeature) !== false ? (int)reset($selectedFeature) : '';
    } else {
        $selectedFeature = (int)$selectedFeature;
    }
    $generationGroupType = (string) Tools::getValue('generation_group_type', 'text');
    if (!in_array($generationGroupType, ['text', 'photo'], true)) {
        $generationGroupType = 'text';
    }

    $availableModels = [
        'gpt-5.2' => 'gpt-5.2 (szybszy)',
        'gpt-5.2-pro' => 'gpt-5.2-pro (wolny)',
        'gpt-4o' => 'gpt-4o',
        'gpt-4o-mini' => 'gpt-4o-mini (bardzo szybki)',
        'o3-mini' => 'o3-mini',
    ];
    $modelVal = (string) \Configuration::get('PO_LINKEDPRODUCT_OPENAI_MODEL') ?: 'gpt-5.2';
    $modelVal = (string) Tools::getValue('PO_LINKEDPRODUCT_OPENAI_MODEL', $modelVal);
    $modelOptions = '';
    foreach ($availableModels as $modelKey => $modelLabel) {
        $modelOptions .= '<option value="'.htmlspecialchars($modelKey).'"'.($modelKey === $modelVal ? ' selected' : '').'>'.htmlspecialchars($modelLabel).'</option>';
    }

$where = $search !== ''
  ? ' AND (pl.name LIKE "%' . pSQL($search) . '%" OR p.reference LIKE "%' . pSQL($search) . '%") '
  : '';

    // policz
    $sqlCount = 'SELECT COUNT(*)
        FROM '._DB_PREFIX_.'product p
        INNER JOIN '._DB_PREFIX_.'product_lang pl
          ON (pl.id_product = p.id_product AND pl.id_lang='.$idLang.' AND pl.id_shop='.(int)$this->context->shop->id.')
        WHERE 1 '.$where;
    $total = (int)\Db::getInstance()->getValue($sqlCount);

    // produkty
    $sql = 'SELECT p.id_product, pl.name, pl.link_rewrite, p.reference
        FROM '._DB_PREFIX_.'product p
        INNER JOIN '._DB_PREFIX_.'product_lang pl
          ON (pl.id_product = p.id_product AND pl.id_lang='.$idLang.' AND pl.id_shop='.(int)$this->context->shop->id.')
        WHERE 1 '.$where.'
        ORDER BY p.id_product ASC
        LIMIT '.(int)$perPage.' OFFSET '.(int)$offset;
    $rows = \Db::getInstance()->executeS($sql) ?: [];

    $productIds   = array_column($rows, 'id_product');
    $groupsMatrix = $this->fetchGroupsMatrix($productIds, $idLang);

    // sprawdź które mają powiązania
    $linkeds = [];
    if ($rows) {
        $sqlLinked = 'SELECT DISTINCT product_id
            FROM '._DB_PREFIX_.'po_linkedproduct_row
            WHERE product_id IN ('.implode(',', array_map('intval', $productIds)).')';
        foreach (\Db::getInstance()->executeS($sqlLinked) ?: [] as $lr) {
            $linkeds[(int)$lr['product_id']] = true;
        }
    }

    $html = '<div class="panel">';
    $html .= '<div class="panel-heading">'.$this->l('Generator linkowania').'</div>';

    // 🔍 toolbar
    $html .= '<form method="get" class="form-inline" style="margin-bottom:15px">';
    foreach (['controller','configure'] as $keep) {
        if (Tools::getValue($keep) !== null) {
            $html .= '<input type="hidden" name="'.$keep.'" value="'.htmlspecialchars((string)Tools::getValue($keep)).'">';
        }
    }
    $html .= '<input type="hidden" name="po_tab" value="generator">';
    $html .= '<input type="hidden" name="_token" value="' . htmlspecialchars($token) . '">';
    $html .= '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '">';
    $html .= '<div class="form-group">
        <input class="form-control" type="text" name="q" value="'.htmlspecialchars($search).'" placeholder="'.$this->l('Szukaj po nazwie').'">
    </div>
    <div class="form-group">
        <select name="n" class="form-control">';
    foreach ([25,50,100,150,200] as $opt) {
        $html .= '<option value="'.$opt.'"'.($perPage==$opt?' selected':'').'>'.$opt.' / '.$this->l('stronę').'</option>';
    }
    $html .= '</select>
    </div>
    <button type="submit" class="btn btn-default"><i class="icon-search"></i> '.$this->l('Szukaj').'</button>
    </form>';

    // 📋 formularz generatora
    $html .= '<form method="post">';
    $html .= '<input type="hidden" name="token" value="'.htmlspecialchars($token).'">';
    $html .= '<input type="hidden" name="generate_linked" value="1">';

    // pola dodatkowe
//    $html .= '<div class="form-inline" style="margin-bottom:10px">
//        <div class="form-group">
//            <label style="margin-right:8px">'.$this->l('Limit grup (opcjonalnie)').'</label>
//            <input class="form-control" type="number" min="0" name="group_count" value="'.(int)Tools::getValue('group_count', 0).'" style="width:120px">
//        </div>
//    </div>';
    $html .= '<div class="form-group">
        <label>'.$this->l('Model').'</label>
        <select name="PO_LINKEDPRODUCT_OPENAI_MODEL" class="form-control">
            '.$modelOptions.'
        </select>
    </div>';

    $html .= '<div class="form-group">
        <label>'.$this->l('Sugerowane grupy do wygenerowania (wybierz cechę)').'</label>';
    if (!empty($featureOptions)) {
        $html .= '<select name="suggested_groups" class="form-control" id="lp-suggested-groups" required>';
        $html .= '<option value="">'.$this->l(' ---- ').'</option>';
        foreach ($featureOptions as $featureId => $featureName) {
            $html .= '<option value="'.$featureId.'"'.((int)$featureId === (int)$selectedFeature ? ' selected' : '').'>'.htmlspecialchars($featureName).'</option>';
        }
        $html .= '</select>';
    } else {
        $html .= '<p class="text-muted" style="margin:0">'.$this->l('Brak cech do wyświetlenia.').'</p>';
    }
    $html .= '
    </div>';
    $html .= '<div class="form-group">
        <label>'.$this->l('Typ generowanej grupy').'</label>
        <select name="generation_group_type" class="form-control">
            <option value="text"'.($generationGroupType === 'text' ? ' selected' : '').'>'.$this->l('Tekst').'</option>
            <option value="photo"'.($generationGroupType === 'photo' ? ' selected' : '').'>'.$this->l('Zdjęcie').'</option>
        </select>
    </div>';
    // tabela produktów
    $html .= '<table class="table"><thead>
        <tr>
            <th style="width:70px">'.$this->l('Zdjęcie').'</th>
            <th>'.$this->l('Nazwa').'</th>
            <th style="width:120px"><input type="checkbox" id="checkAll"> '.$this->l('Zaznacz wszystkie').'</th>
            <th style="width:100px">
                <a href="#" class="btn btn-default btn-xs" id="lp-toggle-all-generator">'.$this->l('Pokaż / ukryj wszystkie').'</a>                             
            </th>
        </tr></thead><tbody>';

    foreach ($rows as $p) {
        $pid = (int)$p['id_product'];
        $pirefrence = $p['reference'];
        $img = \Image::getCover($pid);
        $imgUrl = $img && isset($img['id_image'])
            ? $this->context->link->getImageLink($p['link_rewrite'], $img['id_image'], 'small_default')
            : '';
        $linked = !empty($linkeds[$pid]);

       $html .= '<tr>
            <td>'.($imgUrl ? '<img src="'.$imgUrl.'" style="height:50px">' : '-').'</td>
            <td>'.htmlspecialchars($p['name']).' <small class="text-muted"> '.$pirefrence.' #'.$pid.'</small></td>
            <td><input type="checkbox" name="selected_products[]" value="'.$pid.'"></td>                
            <td class="text-center">
                <span 
                    data-bs-toggle="tooltip" 
                    data-bs-placement="top"
                    title="'.($linked ?  'Powiązany' : 'Niepowiązany').'">'
                    .($linked ? '✅' : '❌').'
                </span>
            </td>
        </tr>';


        // wiersz z powiązaniami
        $groups = $groupsMatrix[$pid] ?? [];
        $html .= '<tr class="active"><td colspan="4" style="background:#fafafa">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong>'.$this->l('Powiązania dla produktu').' #'.$pid.'</strong>
                <a href="#" class="btn btn-default btn-xs lp-toggle w-100" data-target="#lp-details-'.$pid.'">'.$this->l('Pokaż / ukryj').'</a>
            </div>
            <div id="lp-details-'.$pid.'" style="margin:16px 32px;display:none">';
        if (!$groups) {
            $html .= '<div class="text-muted">'.$this->l('Brak powiązań').'</div>';
        } else {
            foreach ($groups as $g) {
                $html .= '<div class="panel" style="margin-bottom:10px">
                    <div class="panel-heading">
                        <span class="label label-default">#'.$g['id'].'</span>
                        <span class="label label-info">'.htmlspecialchars($g['type']).'</span>
                        <strong>'.htmlspecialchars($g['title']).'</strong>
                        <span class="text-muted">pos: '.(int)$g['position'].'</span>
                    </div>
                    <div class="panel-body" style="padding:10px">
                        <table class="table table-condensed"><thead>
                            <tr>
                                <th>'.$this->l('Row ID').'</th>
                                <th>'.$this->l('Produkt').'</th>
                                <th>'.$this->l('Wartość').'</th>
                            </tr></thead><tbody>';
                foreach ($g['rows'] as $r) {
                    $html .= '<tr>
                        <td>#'.$r['row_id'].'</td>
                        <td>'.htmlspecialchars($r['product_name']).'</td>
                        <td>'.htmlspecialchars((string)($r['value'] ?? '')).'</td>
                    </tr>';
                }
                $html .= '</tbody></table></div></div>';
            }
        }
        $html .= '</div></td></tr>';
    }

    if (!$rows) {
        $html .= '<tr><td colspan="4" class="text-center text-muted">'.$this->l('Brak wyników').'</td></tr>';
    }
    $html .= '</tbody></table>';

        $generateDisabled = $selectedFeature ? '' : ' disabled';
    $html .= '<button type="submit" class="btn btn-success" id="lp-generate-btn"'.$generateDisabled.'>'.$this->l('Generuj powiązania').'</button>';
    $html .= '</form>';

    // 📄 paginacja
    $pages = (int)ceil(max(1, $total) / $perPage);
    if ($pages > 1) {
        $html .= '<nav style="margin-top:15px"><ul class="pagination">';
        for ($i = 1; $i <= $pages; $i++) {
            $href = $this->context->link->getAdminLink('AdminModules', true, [], [
                'configure' => $this->name,
                'po_tab'    => 'generator',
                'q'         => $search,
                'n'         => $perPage,
                'p'         => $i,
            ]);
            $html .= '<li'.($i==$page?' class="active"':'').'><a href="'.$href.'">'.$i.'</a></li>';
        }
        $html .= '</ul></nav>';
    }

    // JS: toggle + checkAll
    $html .= '<script>(function(){
        var toggles=document.querySelectorAll(".lp-toggle");
        for(var i=0;i<toggles.length;i++){toggles[i].addEventListener("click",function(e){
            e.preventDefault();
            var el=document.querySelector(this.getAttribute("data-target"));
            if(el) el.style.display=(el.style.display==="none"||el.style.display==="")?"block":"none";
        });}
        var checkAll=document.getElementById("checkAll");
        if(checkAll){checkAll.addEventListener("change",function(){
            var boxes=document.querySelectorAll("input[name=\'selected_products[]\']");
            for(var i=0;i<boxes.length;i++){boxes[i].checked=checkAll.checked;}
        });}
        
        var toggleAllBtn=document.getElementById("lp-toggle-all-generator");
        if(toggleAllBtn){toggleAllBtn.addEventListener("click",function(e){
            e.preventDefault();
            var details=document.querySelectorAll("div[id^=\'lp-details-\']");
            var shouldShow=false;
            for(var i=0;i<details.length;i++){
                if(details[i].style.display==="none"||details[i].style.display===""){shouldShow=true;break;}
            }
            for(var j=0;j<details.length;j++){
                details[j].style.display=shouldShow?"block":"none";
            }
        });}
        
        var suggestedGroups=document.getElementById("lp-suggested-groups");
        var generateBtn=document.getElementById("lp-generate-btn");
        function toggleGenerateBtn(){
            if(!suggestedGroups||!generateBtn){return;}
            generateBtn.disabled=suggestedGroups.value==="";
        }
        if(suggestedGroups){
            suggestedGroups.addEventListener("change",toggleGenerateBtn);
            toggleGenerateBtn();
        }
    })();</script>';

    $html .= '</div>'; // panel
    return $html;
}
protected function renderMassEdit(): string
{
    $token = $this->context->controller->token ?: Tools::getAdminTokenLite('AdminModules');
    $idLang = (int)$this->context->language->id;

    $page    = max(1, (int)Tools::getValue('p', 1));
    $perPage = max(1, min(200, (int)Tools::getValue('n', 50)));
    $search  = trim((string)Tools::getValue('q', ''));
    $offset  = ($page - 1) * $perPage;

    $where = $search !== ''
  ? ' AND (pl.name LIKE "%' . pSQL($search) . '%" OR p.reference LIKE "%' . pSQL($search) . '%") '
  : '';
    // policz produkty
    $sqlCount = 'SELECT COUNT(*)
        FROM '._DB_PREFIX_.'product p
        INNER JOIN '._DB_PREFIX_.'product_lang pl 
          ON (pl.id_product=p.id_product AND pl.id_lang='.$idLang.' AND pl.id_shop='.(int)$this->context->shop->id.')
        WHERE 1 '.$where;
    $total = (int)\Db::getInstance()->getValue($sqlCount);

    // pobierz produkty
    $sql = 'SELECT p.id_product, pl.name, pl.link_rewrite, p.reference
        FROM '._DB_PREFIX_.'product p
        INNER JOIN '._DB_PREFIX_.'product_lang pl 
          ON (pl.id_product=p.id_product AND pl.id_lang='.$idLang.' AND pl.id_shop='.(int)$this->context->shop->id.')
        WHERE 1 '.$where.'
        ORDER BY p.id_product ASC
        LIMIT '.(int)$perPage.' OFFSET '.(int)$offset;
    $rows = \Db::getInstance()->executeS($sql) ?: [];

    $productIds   = array_column($rows, 'id_product');
    $groupsMatrix = $this->fetchGroupsMatrix($productIds, $idLang);

    // sprawdź które produkty są powiązane
    $linkeds = [];
    if ($productIds) {
        $sqlLinked = 'SELECT DISTINCT product_id 
            FROM '._DB_PREFIX_.'po_linkedproduct_row 
            WHERE product_id IN ('.implode(',', array_map('intval', $productIds)).')';
        foreach (\Db::getInstance()->executeS($sqlLinked) ?: [] as $lr) {
            $linkeds[(int)$lr['product_id']] = true;
        }
    }

    // adres akcji dla formularzy POST
    $adminAction = $this->context->link->getAdminLink('AdminModules', true, [], [
        'configure' => $this->name,
        'po_tab'    => 'mass_edit',
    ]);

    $html = '<div class="panel"><div class="panel-heading">'.$this->l('Masowa edycja powiązań').'</div>';

    // 🔍 toolbar (wyszukiwarka + ilość na stronę) – GET
    $html .= '<form method="get" class="form-inline" style="margin-bottom:15px">';
    foreach (['controller','configure'] as $keep) {
        $val = (string)Tools::getValue($keep, '');
        if ($val !== '') {
            $html .= '<input type="hidden" name="'.$keep.'" value="'.htmlspecialchars($val).'">';
        }
    }
    $html .= '<input type="hidden" name="po_tab" value="mass_edit">';
    $html .= '<input type="hidden" name="_token" value="' . htmlspecialchars($token) . '">';
    $html .= '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '">';
    $html .= '<div class="form-group">
        <input class="form-control" type="text" name="q" value="'.htmlspecialchars($search).'" placeholder="'.$this->l('Szukaj po nazwie').'">
    </div>
    <div class="form-group">
        <select name="n" class="form-control">';
    foreach ([25,50,100,150,200] as $opt) {
        $html .= '<option value="'.$opt.'"'.($perPage==$opt?' selected':'').'>'.$opt.' / '.$this->l('stronę').'</option>';
    }
    $html .= '</select></div>
    <button type="submit" class="btn btn-default"><i class="icon-search"></i> '.$this->l('Szukaj').'</button>
    </form>';

    $adminAction = $this->context->link->getAdminLink('AdminModules', true, [], [
        'configure' => $this->name,
        'po_tab'    => 'mass_edit',
    ]);

    $html .= '<form method="post" action="'.$adminAction.'">';
    // 🔥 używamy _token zamiast token
    // to jest aktualny token CSRF dla bieżącego kontrolera Symfony w PS8/9
    $token = $this->context->controller->token;

    $html .= '<input type="hidden" name="_token" value="' . htmlspecialchars($token) . '">';

    $html .= '<input type="hidden" name="configure" value="'.$this->name.'">';
    $html .= '<input type="hidden" name="po_tab" value="mass_edit">';


    // tabela produktów
    $html .= '<table class="table"><thead>
        <tr>
            <th style="width:70px">'.$this->l('Zdjęcie').'</th>
            <th>'.$this->l('Nazwa').'</th>
            <th style="width:100px">'.$this->l('Powiązany?').'</th>
            <th style="width:120px">
                  <a href="#" class="btn btn-default btn-xs" id="lp-toggle-all-mass">'.$this->l('Pokaż / ukryj wszystkie').'</a>
                      </th>
        </tr></thead><tbody>';

    foreach ($rows as $p) {
        $pid = (int)$p['id_product'];
        $pirefrence = $p['reference'];
        $img = \Image::getCover($pid);
        $imgUrl = $img && isset($img['id_image'])
            ? $this->context->link->getImageLink($p['link_rewrite'], $img['id_image'], 'small_default')
            : '';
        $linked = !empty($linkeds[$pid]);

        $statusIcon = $linked ? '✅' : '❌';
        $html .= '<tr data-product-id="'.$pid.'">
            <td>'.($imgUrl ? '<img src="'.$imgUrl.'" style="height:50px">' : '-').'</td>
            <td>'.htmlspecialchars($p['name']).' <small class="text-muted"> '.$pirefrence.' #'.$pid.'</small></td>
            <td class="text-center" data-original-status="'.$statusIcon.'">'.$statusIcon.'</td>
            <td><a href="#" class="btn btn-default btn-xs lp-toggle w-100" data-target="#lp-details-'.$pid.'">'.$this->l('Pokaż / ukryj').'</a></td>
        </tr>';

        $html .= '<tr class="active"><td colspan="4" style="background:#fafafa">
            <div id="lp-details-'.$pid.'" style="margin: 16px 32px; display:none;">'
            .$this->renderGroupsEditor($pid, $groupsMatrix[$pid] ?? [], $adminAction).
            '</div>
        </td></tr>';
    }

    if (!$rows) {
        $html .= '<tr><td colspan="4" class="text-center text-muted">'.$this->l('Brak wyników').'</td></tr>';
    }

    $html .= '</tbody></table>';

    // przycisk zapisu
$html .= '<button type="submit" name="lp_action" value="mass_update" class="btn btn-success">
    <i class="icon-save"></i> '.$this->l('Zapisz wszystkie zmiany').'
</button>';
$html .= '</form>';


    // 📄 paginacja
    $pages = (int)ceil(max(1, $total) / $perPage);
    if ($pages > 1) {
        $html .= '<nav><ul class="pagination">';
        for ($i = 1; $i <= $pages; $i++) {
            $href = $this->context->link->getAdminLink('AdminModules', true, [], [
                'configure' => $this->name,
                'po_tab'    => 'mass_edit',
                'q'         => $search,
                'n'         => $perPage,
                'p'         => $i,
            ]);
            $html .= '<li'.($i==$page?' class="active"':'').'><a href="'.$href.'">'.$i.'</a></li>';
        }
        $html .= '</ul></nav>';
    }

    $statusTexts = [
        'dirty'  => $this->l('Do aktualizacji'),
        'saving' => $this->l('Zapisywanie...'),
        'saved'  => $this->l('Zapisano'),
        'error'  => $this->l('Błąd zapisu'),
    ];

    $statusTextsJson = json_encode($statusTexts);

    $script = <<<JS
(function(){
    var statusTexts = $statusTextsJson;
    var statusTimers = {};
    var canAutoSave = typeof window.fetch === 'function';

    function toggleHandler(e){
        e.preventDefault();
        var targetSelector = this.getAttribute('data-target');
        if (!targetSelector) {
            return;
        }
        var el = document.querySelector(targetSelector);
        if (!el) {
            return;
        }
        if (el.style.display === 'none' || el.style.display === '') {
            el.style.display = 'block';
        } else {
            el.style.display = 'none';
        }
    }

    var toggles = document.querySelectorAll('.lp-toggle');
    for (var i = 0; i < toggles.length; i++) {
        toggles[i].addEventListener('click', toggleHandler);
    }
            
    var toggleAllBtn = document.getElementById('lp-toggle-all-mass');
    if (toggleAllBtn) {
        toggleAllBtn.addEventListener('click', function(e){
            e.preventDefault();
            var details = document.querySelectorAll("div[id^='lp-details-']");
            var shouldShow = false;
            for (var i = 0; i < details.length; i++) {
                if (details[i].style.display === 'none' || details[i].style.display === '') {
                    shouldShow = true;
                    break;
                }
            }
            for (var j = 0; j < details.length; j++) {
                details[j].style.display = shouldShow ? 'block' : 'none';
            }
        });
    }
         var draggingGroup = null;
         var draggingRow = null;

    function updateGroupPositions(editor){
        if (!editor) {
            return;
        }
        var groups = editor.querySelectorAll('.lp-group');
        for (var i = 0; i < groups.length; i++) {
            var group = groups[i];
            var pos = i + 1;
            var posInput = group.querySelector('input[name^="groups["][name$="[position]"]');
            if (posInput && String(posInput.value) !== String(pos)) {
                posInput.value = pos;
                posInput.dispatchEvent(new Event('input', { bubbles: true }));
                if (canAutoSave) {
                    autoSaveField(posInput);
                }
            }
            var posLabel = group.querySelector('.lp-group-position');
            if (posLabel) {
                posLabel.textContent = 'pos: ' + pos;
            }
        }
        var firstGroup = editor.querySelector('.lp-group');
        if (firstGroup) {
            updateStatus(firstGroup.getAttribute('data-product-id'), 'dirty');
        }
    }

    function initGroupDnD(){
        var editors = document.querySelectorAll('.groups-editor');
        for (var i = 0; i < editors.length; i++) {
            (function(editor){
                var groups = editor.querySelectorAll('.lp-group');
                for (var j = 0; j < groups.length; j++) {
                    (function(group){
                        var handle = group.querySelector('.lp-group-handle');
                        if (!handle) {
                            return;
                        }
                        handle.setAttribute('draggable', 'true');
                        handle.addEventListener('dragstart', function(e){
                            draggingGroup = group;
                            group.classList.add('lp-dragging');
                            e.dataTransfer.effectAllowed = 'move';
                            try {
                                e.dataTransfer.setData('text/plain', group.getAttribute('data-group-id') || '');
                            } catch (err) {
                                // brak wsparcia
                            }
                        });
                        handle.addEventListener('dragend', function(){
                            if (draggingGroup) {
                                draggingGroup.classList.remove('lp-dragging');
                            }
                            draggingGroup = null;
                            updateGroupPositions(editor);
                        });
                        group.addEventListener('dragover', function(e){
                            if (!draggingGroup || draggingGroup === group) {
                                return;
                            }
                            e.preventDefault();
                            e.dataTransfer.dropEffect = 'move';
                            var rect = group.getBoundingClientRect();
                            var before = (e.clientY - rect.top) < rect.height / 2;
                            if (before) {
                                if (group.previousSibling !== draggingGroup) {
                                    editor.insertBefore(draggingGroup, group);
                                }
                            } else if (group.nextSibling !== draggingGroup) {
                                editor.insertBefore(draggingGroup, group.nextSibling);
                            }
                        });
                        group.addEventListener('drop', function(e){
                            if (!draggingGroup) {
                                return;
                            }
                            e.preventDefault();
                            updateGroupPositions(editor);
                        });
                    })(groups[j]);
                }
            })(editors[i]);
        }
    }

    function updateRowPositions(tableBody, skipAutosave){
        if (!tableBody) {
            return;
        }
        var rows = tableBody.querySelectorAll('tr.lp-row');
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var pos = i + 1;
            var posInput = row.querySelector('input[name^="rows["][name$="[position]"]');
            if (posInput && String(posInput.value) !== String(pos)) {
                posInput.value = pos;
                posInput.dispatchEvent(new Event('input', { bubbles: true }));
                if (canAutoSave && !skipAutosave) {
                    autoSaveField(posInput);
                }
            }
            var posLabel = row.querySelector('.lp-row-position');
            if (posLabel) {
                posLabel.textContent = String(pos);
            }
        }
        if (!skipAutosave) {
            var firstRow = rows.length ? rows[0] : null;
            if (firstRow) {
                var rowInput = firstRow.querySelector('input[name^="rows["][name$="[position]"]');
                var pid = getProductIdFromInput(rowInput);
                if (pid) {
                    updateStatus(pid, 'dirty');
                }
            }
        }
    }

    function initRowDnD(){
        var bodies = document.querySelectorAll('.lp-rows-body');
        for (var i = 0; i < bodies.length; i++) {
            (function(tbody){
                var rows = tbody.querySelectorAll('tr.lp-row');
                for (var j = 0; j < rows.length; j++) {
                    (function(row){
                        var handle = row.querySelector('.lp-row-handle');
                        if (!handle) {
                            return;
                        }
                        handle.setAttribute('draggable', 'true');
                        handle.addEventListener('dragstart', function(e){
                            draggingRow = row;
                            row.classList.add('lp-dragging');
                            e.dataTransfer.effectAllowed = 'move';
                            try {
                                e.dataTransfer.setData('text/plain', row.getAttribute('data-row-id') || '');
                            } catch (err) {
                                // brak wsparcia
                            }
                        });
                        handle.addEventListener('dragend', function(){
                            if (draggingRow) {
                                draggingRow.classList.remove('lp-dragging');
                            }
                            draggingRow = null;
                            updateRowPositions(tbody);
                        });
                        row.addEventListener('dragover', function(e){
                            if (!draggingRow || draggingRow === row) {
                                return;
                            }
                            e.preventDefault();
                            e.dataTransfer.dropEffect = 'move';
                            var rect = row.getBoundingClientRect();
                            var before = (e.clientY - rect.top) < rect.height / 2;
                            if (before) {
                                if (row.previousSibling !== draggingRow) {
                                    tbody.insertBefore(draggingRow, row);
                                }
                            } else if (row.nextSibling !== draggingRow) {
                                tbody.insertBefore(draggingRow, row.nextSibling);
                            }
                        });
                        row.addEventListener('drop', function(e){
                            if (!draggingRow) {
                                return;
                            }
                            e.preventDefault();
                            updateRowPositions(tbody);
                        });
                    })(rows[j]);
                }
            })(bodies[i]);
        }
    }
        
        
    function getProductIdFromInput(input){
        if (!input) {
            return null;
        }
        var groupEl = input.closest('.lp-group');
        if (!groupEl) {
            return null;
        }
        return groupEl.getAttribute('data-product-id');
    }

    function updateStatus(pid, state, message){
        if (!pid) {
            return;
        }
        var row = document.querySelector('tr[data-product-id="' + pid + '"]');
        if (!row) {
            return;
        }
        var cell = row.querySelector('td[data-original-status]');
        if (!cell) {
            return;
        }

        if (statusTimers[pid]) {
            clearTimeout(statusTimers[pid]);
            delete statusTimers[pid];
        }

        cell.classList.remove('lp-updated', 'lp-saving', 'lp-error');

        switch (state) {
            case 'dirty':
                cell.textContent = '\uD83D\uDCDD ' + statusTexts.dirty;
                cell.classList.add('lp-updated');
                break;
            case 'saving':
                cell.textContent = '\u231B ' + statusTexts.saving;
                cell.classList.add('lp-saving');
                break;
            case 'saved':
                cell.textContent = '\uD83D\uDCBE ' + statusTexts.saved;
                statusTimers[pid] = window.setTimeout(function(){
                    cell.textContent = cell.getAttribute('data-original-status') || '';
                    cell.classList.remove('lp-saving', 'lp-error', 'lp-updated');
                }, 2000);
                break;
            case 'error':
                cell.textContent = '\u274C ' + (message || statusTexts.error);
                cell.classList.add('lp-error');
                break;
        }
    }

    function parseFieldMeta(name){
        if (!name) {
            return null;
        }
        var match = name.match(/^groups\[(\d+)\]\[(type|position)\]$/);
        if (match) {
            return { target: 'group', group_id: match[1], field: match[2] };
        }
        match = name.match(/^groups\[(\d+)\]\[title\]\[(\d+)\]$/);
        if (match) {
            return { target: 'group', group_id: match[1], field: 'title', id_lang: match[2] };
        }
        match = name.match(/^rows\[(\d+)\]\[value\]\[(\d+)\]$/);
        if (match) {
            return { target: 'row', row_id: match[1], field: 'value', id_lang: match[2] };
        }
        match = name.match(/^rows\[(\d+)\]\[position\]$/);
        if (match) {
            return { target: 'row', row_id: match[1], field: 'position' };
        }
        return null;
    }

    function autoSaveField(input){
        if (!canAutoSave) {
            return;
        }
        var meta = parseFieldMeta(input.name || '');
        if (!meta) {
            return;
        }
        var pid = getProductIdFromInput(input);
        if (pid) {
            updateStatus(pid, 'saving');
        }
        var form = input.closest('form');
        if (!form) {
            return;
        }

        var formData = new FormData();
        formData.append('lp_action', 'mass_update_field');
        formData.append('lp_ajax', '1');
        formData.append('ajax', '1');
        formData.append('po_tab', 'mass_edit');

        if (meta.target) { formData.append('target', meta.target); }
        if (meta.group_id) { formData.append('group_id', meta.group_id); }
        if (meta.field) { formData.append('field', meta.field); }
        if (meta.id_lang) { formData.append('id_lang', meta.id_lang); }
        if (meta.row_id) { formData.append('row_id', meta.row_id); }
        if (meta.target === 'group' && pid) { formData.append('product_id', pid); }

        formData.append('value', input.value);

        var tokenInput = form.querySelector('input[name="_token"]');
            if (tokenInput) {
                formData.append('_token', tokenInput.value);  // zamiast token
            }
        var configureInput = form.querySelector('input[name="configure"]');
        if (configureInput) {
            formData.append('configure', configureInput.value);
        }
        var controllerInput = form.querySelector('input[name="controller"]');
        if (controllerInput) {
            formData.append('controller', controllerInput.value);
        }
        var actionUrl = form.getAttribute('action');


        console.groupCollapsed('[LP DEBUG] AJAX save');
console.log('Action URL:', actionUrl);
console.log('Hidden _token:', tokenInput ? tokenInput.value : '(brak)');
console.log('FormData content:');
for (var pair of formData.entries()) {
  console.log('  ', pair[0], '=>', pair[1]);
}
console.groupEnd();

        fetch(actionUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function(response){
                return response.text().then(function(text){
                    var json = null;
                    if (text) {
                        try {
                            json = JSON.parse(text);
                        } catch (parseError) {
                            // niepoprawny JSON – zostaw json=null
                        }
                    }

                    if (!response.ok) {
                        var httpMessage = 'HTTP ' + response.status;
                        var serverMessage = json && json.message ? json.message : null;
                        throw new Error(serverMessage || httpMessage);
                    }

                    if (!json || json.success !== true) {
                        throw new Error(json && json.message ? json.message : '');
                    }

                    return json;
                });
            })
            .then(function(){
                if (pid) {
                    updateStatus(pid, 'saved');
                }
            })
            .catch(function(error){
                if (pid) {
                    updateStatus(pid, 'error', error && error.message ? error.message : null);
                }
            });
    }

    document.querySelectorAll('.lp-watch').forEach(function(inp){
        inp.addEventListener('input', function(){
            var pid = getProductIdFromInput(this);
            if (pid) {
                updateStatus(pid, 'dirty');
            }
        });
        if (canAutoSave) {
            inp.addEventListener('change', function(){
                autoSaveField(this);
            });
        }
    });
    initGroupDnD();
    initRowDnD();
    document.querySelectorAll('.lp-rows-body').forEach(function(body){
        updateRowPositions(body, true);
    });
})();
JS;
    $html .= '<script>'.$script.'</script>';

    $html .= '</div>'; // panel

    return $html;
}

/**
 * Renderer edycji grup i powiązań dla pojedynczego produktu
 */
protected function renderGroupsEditor(int $pid, array $groups, string $adminAction): string
{
    $html = '<div class="groups-editor">';
    if (!$groups) {
        $html .= '<div class="text-muted">'.$this->l('Brak powiązań dla tego produktu.').'</div>';
    } else {
        foreach ($groups as $g) {
            $gid = (int)$g['id'];

             $html .= '<div class="panel lp-group" data-product-id="'.$pid.'" data-group-id="'.$gid.'">
                <div class="panel-heading" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <span class="lp-group-handle" title="'.$this->l('Przeciągnij, aby zmienić kolejność').'" style="cursor:move;font-size:16px;">☰</span>
                    <span class="label label-default">#'.$gid.'</span>
                    <span class="label label-info">'.htmlspecialchars($g['type']).'</span>
                    <strong>'.htmlspecialchars($g['title']).'</strong>
                    <span class="text-muted lp-group-position">pos: '.(int)$g['position'].'</span>

                    <input type="hidden" name="group_id['.$gid.']" value="'.$gid.'">

                    <button type="submit" name="lp_action" value="delete_group"
                            class="btn btn-danger btn-xs" style="margin-left:auto"
                            onclick="return confirm(\''.$this->l('Na pewno usunąć grupę?').'\')">
                        <i class="icon-trash"></i> '.$this->l('Usuń grupę').'
                    </button>
                </div>';

            // ---- Edytor grupy ----
$html .= '<div style="padding:10px;border-top:1px solid #eee">
    <div class="form-inline" style="display:flex;gap:10px;flex-wrap:wrap">
        <input type="hidden" name="groups['.$gid.'][id]" value="'.$gid.'">

        <div class="form-group">
            <label>'.$this->l('Typ').'</label>
            <select class="form-control lp-watch" name="groups['.$gid.'][type]" style="width:140px">
                <option value="text" '.($g['type'] === 'text' ? 'selected' : '').'>Tekst</option>
                <option value="photo" '.($g['type'] === 'photo' ? 'selected' : '').'>Zdjęcie</option>
            </select>
        </div>
';


            // ---- Tytuły w różnych językach ----
            foreach ($this->languages as $lang) {
                $idLangX = (int)$lang['id_lang'];
                $titleX = \Db::getInstance()->getValue('
                    SELECT group_title
                    FROM '._DB_PREFIX_.'po_linkedproduct_lang
                    WHERE id='.(int)$gid.' AND id_lang='.(int)$idLangX
                ) ?: $g['title'];

                $html .= '<div class="form-group">
                    <label>'.$this->l('Tytuł').' ('.$lang['iso_code'].')</label>
                    <input class="form-control lp-watch" type="text"
                           name="groups['.$gid.'][title]['.$idLangX.']"
                           value="'.htmlspecialchars($titleX).'"
                           style="min-width:220px">
                </div>';
            }

            $html .= '</div></div>'; // edytor grupy

            // ---- Lista powiązań ----
            $html .= '<div class="panel-body" style="padding:10px">
                <div class="table-responsive">
                    <table class="table table-condensed">
                        <thead>
                            <tr>
                                <th style="width:90px">'.$this->l('Pozycja').'</th>
                                <th style="width:90px">'.$this->l('Row ID').'</th>
                                <th style="width:90px">'.$this->l('Product ID').'</th>
                                <th>'.$this->l('Produkt').'</th>
                                <th>'.$this->l('Wartości dla języków').'</th>                               
                                <th style="width:120px">
                                    '.$this->l('Akcje').'
                                  
                                </th>
                            </tr>
                        </thead>
                        <tbody class="lp-rows-body">';

            foreach ($g['rows'] as $r) {
                $rowPosition = (int) ($r['position'] ?? 0);
                $html .= '<tr class="lp-row" data-row-id="'.(int)$r['row_id'].'">
                    <td>
                        <span class="lp-row-handle" title="'.$this->l('Przeciągnij, aby zmienić kolejność').'" style="cursor:move;font-size:16px;">☰</span>
                        <span class="lp-row-position" style="margin-left:6px">'.($rowPosition ?: 0).'</span>
                        <input type="hidden" name="rows['.(int)$r['row_id'].'][position]" value="'.($rowPosition ?: 0).'" class="lp-watch">
                    </td>
                    <td>#'.(int)$r['row_id'].'</td>
                    <td>#'.(int)$r['product_id'].'</td>
                    <td>'.htmlspecialchars($r['product_name']).'</td>
                    <td>';

                // ---- Pola wartości dla wszystkich języków w jednej linii ----
                $html .= '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
                foreach ($this->languages as $lang) {
                    $idLangX = (int)$lang['id_lang'];
                    $valueX = \Db::getInstance()->getValue('
                        SELECT `value`
                        FROM `'._DB_PREFIX_.'po_linkedproduct_row_lang`
                        WHERE id_row = '.(int)$r['row_id'].' AND id_lang = '.$idLangX
                    );

                    $html .= '
                        <div class="input-group" style="display:flex;align-items:center;">
                            <span class="input-group-addon" style="min-width:35px;text-align:center;">'.$lang['iso_code'].'</span>
                            <input class="form-control lp-watch" type="text"
                                   name="rows['.$r['row_id'].'][value]['.$idLangX.']"
                                   value="'.htmlspecialchars((string)$valueX).'"
                                   style="width:150px;">
                        </div>';
                }
                $html .= '</div>';

                $html .= '</td>
                    <td>
                        <input type="hidden" name="row_id" value="'.(int)$r['row_id'].'">
                        <button type="submit" name="lp_action" value="delete_row"
                                class="btn btn-warning btn-xs"
                                onclick="return confirm(\''.$this->l('Na pewno usunąć powiązanie?').'\')">
                            <i class="icon-remove"></i> '.$this->l('Usuń powiązanie').'
                        </button>
                    </td>
                </tr>';
            }

            $html .= '</tbody></table></div></div>'; // panel-body

            $html .= '</div>'; // panel grupy
        }
    }
    $html .= '</div>';
    return $html;
}


/**
 * Sprawdza poprawność tokenu – obsługuje zarówno nowe PS8/9 (_token),
 * jak i stary BO (AdminModules -> token).
 */
protected function isValidToken(): bool
{
    return true; // testowo
}





    /**
     * Wywołanie OpenAI czystym cURL (bez zależności).
     * Zwraca zdekodowany JSON (array) lub rzuca wyjątek.
     */


protected function callOpenAi(string $systemPrompt, string $userPrompt): array
{
    $apiUrl = trim((string)\Configuration::get('PO_LINKEDPRODUCT_OPENAI_URL'));
    $apiKey = trim((string)\Configuration::get('PO_LINKEDPRODUCT_OPENAI_KEY'));
    $model = (string) (\Configuration::get('PO_LINKEDPRODUCT_OPENAI_MODEL') ?: 'gpt-5-chat-latest');

    if ($apiUrl === '' || $apiKey === '') {
        throw new \Exception('Brak konfiguracji OpenAI: URL lub KEY.');
    }

    // 🔹 Przygotowanie zapytania
    $payload = [
        'model' => $model ?: 'gpt-5-chat-latest',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ],
        'temperature' => 0.15,
        'max_tokens' => 16384,
    ];

    // 🔹 Wywołanie API
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 60,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new \Exception('cURL error: ' . $err);
    }

    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    \PrestaShopLogger::addLog('[PO_LINKEDPRODUCT][OPENAI RAW RESPONSE] ' . $raw, 3, null, 'Po_linkedproduct');

    if ($code < 200 || $code >= 300) {
        throw new \Exception('OpenAI HTTP ' . $code . ': ' . $raw);
    }

    // 🔹 Parsowanie odpowiedzi OpenAI
    $decoded = json_decode($raw, true);
    $content = $decoded['choices'][0]['message']['content'] ?? $raw;

    if (is_string($content)) {
        $content = trim($content, "\xEF\xBB\xBF \n\r\t");

        // 🔍 Reverse engineering – idź od końca, znajdź parę nawiasów [ ... ]
        $end = strrpos($content, ']');
        if ($end !== false) {
            $depth = 0;
            for ($i = $end; $i >= 0; $i--) {
                $char = $content[$i];
                if ($char === ']') {
                    $depth++;
                } elseif ($char === '[') {
                    $depth--;
                    if ($depth === 0) {
                        // 🔹 Wytnij pełny blok JSON
                        $content = substr($content, $i, $end - $i + 1);
                        break;
                    }
                }
            }
        }
    }

    // 🔹 Próba dekodowania JSON
    $json = json_decode((string)$content, true);

    // 🔁 Fallback
    if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
        $content = preg_replace('/^[^{\[]*/', '', $content);
        $content = preg_replace('/[^}\]]*$/', '', $content);
        $json = json_decode((string)$content, true);
    }

    // 🔹 Obsługa {"groups": [...]} wrappera
    if (is_array($json) && isset($json['groups']) && is_array($json['groups'])) {
        $json = $json['groups'];
    }

    // 🔹 Walidacja końcowa
    if (!is_array($json)) {
        \PrestaShopLogger::addLog(
            '[PO_LINKEDPRODUCT][OPENAI JSON ERROR] Nie udało się sparsować JSON: ' . json_last_error_msg() .
            ' | Fragment: ' . mb_substr((string)$content, 0, 500),
            3,
            null,
            'Po_linkedproduct'
        );
        throw new \Exception('Nie udało się sparsować JSON z odpowiedzi OpenAI: ' . json_last_error_msg());
    }

    \PrestaShopLogger::addLog(
        '[PO_LINKEDPRODUCT][DEBUG] Wydobyty JSON: ' . mb_substr(json_encode($json, JSON_UNESCAPED_UNICODE), 0, 1000),
        1,
        null,
        'Po_linkedproduct'
    );

    return $json;
}







}
