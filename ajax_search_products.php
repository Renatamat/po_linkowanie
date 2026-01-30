<?php
require_once(dirname(__FILE__).'/../../config/config.inc.php');
require_once(dirname(__FILE__).'/../../init.php');

if (Tools::getValue('search')) {
  $search = Tools::getValue('search');
  $searchSql = pSQL($search);
  $searchId = (int) $search;
  $sql = new DbQuery();
  $sql->select('p.id_product, pl.name, p.reference');
  $sql->from('product', 'p');
  $sql->innerJoin('product_lang', 'pl', 'p.id_product = pl.id_product');
//  $sql->where('pl.name LIKE \'%'.pSQL($search).'%\' AND pl.id_lang = '.(int)Context::getContext()->language->id);
    $searchConditions = array(
    'pl.name LIKE \'%'.$searchSql.'%\'',
    'p.reference LIKE \'%'.$searchSql.'%\'',
  );
  if ($searchId > 0) {
    $searchConditions[] = 'p.id_product = '.$searchId;
  }
  $sql->where(
    'pl.id_lang = '.(int) Context::getContext()->language->id
    .' AND ('.implode(' OR ', $searchConditions).')'
  );
  $sql->orderBy('pl.name ASC');
  
  $results = Db::getInstance()->executeS($sql);
  
  if ($results) {
    echo json_encode($results);
  } else {
    echo json_encode(array());
  }
} else {
  echo json_encode(array());
}
?>