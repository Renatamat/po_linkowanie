<?php
/**
* 2007-2023 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2023 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/
$sql = array();

// Create table for linking products groups
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'po_linkedproduct` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `type` VARCHAR(255) NOT NULL,
    `position` INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;';

// Create table for per-product group positions
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'po_linkedproduct_position` (
    `product_id` INT(11) NOT NULL,
    `group_id` INT(11) NOT NULL,
    `position` INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`product_id`, `group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;';

// Create table for group names in different languages
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'po_linkedproduct_lang` (
    `id` INT(11) NOT NULL,
    `id_lang` INT(11) NOT NULL,
    `group_title` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`id`, `id_lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;';

// Create table for linking product rows
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'po_linkedproduct_row` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `group_id` INT(11) NOT NULL,
    `product_id` INT(11) NOT NULL,
    `position` INT(11) NOT NULL DEFAULT 0,
    `value` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;';

// Create table for variant names in different languages
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'po_linkedproduct_row_lang` (
    `id_row` INT(11) NOT NULL,
    `id_lang` INT(11) NOT NULL,
    `value` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`id_row`, `id_lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;';

foreach ($sql as $query) {
    if (Db::getInstance()->execute($query) == false) {
        return false;
    }
}
