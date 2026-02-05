<?php

$sql = array();

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'po_link_profile` (
    `id_profile` INT(11) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(128) NOT NULL,
    `options_csv` VARCHAR(64) NOT NULL,
    `family_csv` VARCHAR(255) NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id_profile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'po_link_profile_label` (
    `id_profile` INT(11) NOT NULL,
    `id_feature` INT(11) NOT NULL,
    `id_lang` INT(11) NOT NULL,
    `label` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`id_profile`, `id_feature`, `id_lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'po_link_product_family` (
    `id_product` INT(11) NOT NULL,
    `id_profile` INT(11) NOT NULL,
    `family_key` VARCHAR(64) NOT NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id_product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'po_link_index` (
    `id_product` INT(11) NOT NULL,
    `id_profile` INT(11) NOT NULL,
    `family_key` VARCHAR(64) NOT NULL,
    `options_json` TEXT NOT NULL,
    PRIMARY KEY (`id_product`),
    KEY `idx_profile_family` (`id_profile`, `family_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;';

foreach ($sql as $query) {
    if (Db::getInstance()->execute($query) == false) {
        return false;
    }
}
