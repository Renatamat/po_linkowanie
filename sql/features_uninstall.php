<?php

$sql = array();
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'po_link_index`';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'po_link_product_family`';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'po_link_profile_label`';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'po_link_profile`';

foreach ($sql as $query) {
    if (Db::getInstance()->execute($query) == false) {
        return false;
    }
}
