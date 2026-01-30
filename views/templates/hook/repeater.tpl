<div id="token">
<input type="hidden" value="{Tools::getAdminTokenLite('AdminProductsController')}" name="token">
</div>
<link rel="stylesheet" href="{$app_css}">
<fieldset>
    <div class="repeater-default">
        <div data-repeater-list="products" class="drag-positions">
            <div class="panel panel-default data-repeater-item d-none" data-repeater-item>
                <input type="hidden" name="linking_products[0][linked_id]" value="" />

                {** Nagłówek jak w istniejących grupach **}
                <div class="panel-heading d-flex justify-content-between align-items-center">
                  <div class="d-flex justify-content-between align-items-center">
                    <span class="group-handle">&#9776;</span>
                    <span class="group-title-display p-2">{l s='New group' mod='po_linkedproduct'}</span>
                  </div>
                  <div class="d-flex justify-content-between align-items-center">
                    <button type="button" class="m-2 btn btn-default btn-xs toggle-group" data-toggle="pstooltip" data-original-title="{l s='Edit' mod='po_linkedproduct'}">
                      {l s='Edit' mod='po_linkedproduct'} <i class="material-icons ml-2">edit</i>
                    </button>
                    <span data-repeater-delete class="m-2 btn btn-danger btn-xs" data-toggle="pstooltip" data-original-title="{l s='Delete' mod='po_linkedproduct'}">
                      {l s='Delete' mod='po_linkedproduct'} <i class="material-icons md48">delete</i>
                    </span>
                  </div>
                </div>

                {** Treść grupy — domyślnie otwarta, żeby można było od razu uzupełnić **}
                <div class="panel-body group-content">
                  <div class="row form-group align-items-center">

                    {** Typ wyświetlania **}
                    <div class="form-group-header  col-sm-12 d-flex flex-wrap">
                      <div class="col-sm-12 col-md-6 d-flex flex-row mb-2 ">
                        <label class="col-auto control-label align-self-center mb-0">
                          <strong>{l s='Choose display type*' mod='po_linkedproduct'}</strong>
                        </label>
                        <div class="col-sm-4">
                          <select name="linking_products[0][type]" class="form-control">
                            <option value="text">Tekst</option>
                            <option value="photo">Zdjęcie</option>
                            <option value="select">Lista</option>
                          </select>
                        </div>
                      </div>

                      {** Pozycja (ukryta) **}
                      <input type="hidden" name="linking_products[0][position]" value="" class="position-input">

                      {** Tytuł grupy (translatable) **}
                      <div class="col-sm-12 col-md-6 d-flex flex-row mb-2 ">
                        <label class="col-auto control-label align-self-center mb-0">
                          <strong>{l s='Enter group title*' mod='po_linkedproduct'}</strong>
                        </label>
                        <div class="col-sm-7">
                          {foreach from=$languages item=language}
                            <div class="translatable-field lang-{$language.id_lang}" {if $language.id_lang != $default_form_language}style="display:none"{/if}>
                              <input type="text" name="linking_products[0][group_title][{$language.id_lang}]" value="" class="form-control">
                              <div class="btn-group">
                                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown">{$language.iso_code}</button>
                                <ul class="dropdown-menu">
                                  {foreach from=$languages item=lang}
                                    <li><a href="javascript:hideOtherLanguage({$lang.id_lang});">{$lang.iso_code}</a></li>
                                  {/foreach}
                                </ul>
                              </div>
                            </div>
                          {/foreach}
                        </div>
                      </div>

                      {** Wyszukiwarka produktów + reset (X) **}
                      <div class="col-sm-12 col-md-6">
                        <label class="col-12 control-label mt-2">
                          <strong>{l s='Product search' mod='po_linkedproduct'}</strong>
                        </label>

                        <div class="input-group-search col-sm-12">
                          <input type="text" class="form-control product-search" data-search-id="1">
                          <span class="input-group-btn">
                            <button type="button" class="btn btn-reset-search" data-target="1" aria-label="{l s='Clear' mod='po_linkedproduct'}">&times;</button>
                          </span>
                        </div>

                        <ul class="product-list" data-list-id="1"></ul>
                      </div>
                    </div>

                    {** Lista wybranych produktów (startowo pusta) **}
                    <div class="col-sm-12">
                      <ul class="product-list-setup" data-list-setup-id="1"></ul>
                    </div>

                  </div>
                </div>
              </div>

            {if $positions|@count > 0}
            {foreach $positions as $index => $position}
            <div class="panel panel-default data-repeater-item" linked-id="{$position.id}" data-repeater-item>
                {assign var="related_products" value=$position.related_products}
                <input  type="hidden" name="linking_products[{$index}][linked_id]" value="{$position.id}" />
                <div class="panel-heading d-flex justify-content-between align-items-center">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="group-handle">&#9776;</span>
                        <span class="group-title-display p-2"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <button type="button" class="m-2 btn btn-default btn-xs toggle-group" data-toggle="pstooltip" data-original-title="{l s='Edit' mod='po_linkedproduct'}">{l s='Edit' mod='po_linkedproduct'} <i class="material-icons ml-2">edit</i></button>
                        <span data-repeater-delete class="m-2 btn btn-danger btn-xs" data-toggle="pstooltip" data-original-title="{l s='Delete' mod='po_linkedproduct'}">{l s='Delete' mod='po_linkedproduct'} <i class="material-icons md48">delete</i> </span>
                    </div>
                </div>
                <div class="panel-body group-content" style="display:none;">
                <div class="row form-group align-items-center ">
                    <div class="form-group-header  col-sm-12 d-flex flex-wrap">
                        <div class="col-sm-12 col-md-6 d-flex  flex-row mb-2 ">
                            <label class="col-auto control-label align-self-center mb-0 "><strong>{l s='Choose display type*' mod='po_linkedproduct'}</strong></label>
                            <div class="col-sm-4">
                                <select name="linking_products[{$index}][type]" class="form-control">
                                    <option value="text"{if $position.type == 'text'} selected{/if}>Tekst</option>
                                    <option value="photo"{if $position.type == 'photo'} selected{/if}>Zdjęcie</option>
                                    <option value="select"{if $position.type == 'select'} selected{/if}>Lista</option>
                                </select>
                            </div>
                        </div>
                        <input type="hidden" name="linking_products[{$index}][position]" value="{$position.position}" class="position-input">
                       <div class="col-sm-12 col-md-6  d-flex  flex-row mb-2">
                        <label class="col-auto control-label align-self-center mb-0"><strong>{l s='Enter group title*' mod='po_linkedproduct'}</strong></label>
                        <div class="col-sm-7">
                            {foreach from=$languages item=language}
                            <div class="translatable-field lang-{$language.id_lang}" {if $language.id_lang != $default_form_language}style="display:none"{/if}>
                                <input type="text" name="linking_products[{$index}][group_title][{$language.id_lang}]" value="{$position.group_title[$language.id_lang]}" class="form-control">
                                <div class="btn-group">
                                    <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown">
                                        {$language.iso_code}
                                    </button>
                                    <ul class="dropdown-menu">
                                    {foreach from=$languages item=lang}
                                        <li><a href="javascript:hideOtherLanguage({$lang.id_lang});">{$lang.iso_code}</a></li>
                                    {/foreach}
                                    </ul>
                                </div>
                            </div>
                            {/foreach}
                        </div>
                       </div>
                        <div class="col-sm-12 col-md-6">
                        <label class="col-12 control-label mt-2"><strong>{l s='Product search' mod='po_linkedproduct'}</strong></label>
                        <div class="input-group-search col-sm-12">
                            <input type="text" class="form-control product-search"
                                data-search-id="{$index + 1}">
                            <span class="input-group-btn">
                                <button type="button" class="btn btn-reset-search" data-target="{$index + 1}">
                                    &times;
                                </button>
                            </span>
                        </div>
                        <ul class="product-list a" data-list-id="{$index + 1}"></ul>
                        </div>
                        
                    </div>
                    <div class="col-sm-12">
                        
                        <ul class="product-list-setup" data-list-setup-id="{$index + 1}">
                            {foreach $related_products as $related_product name=related_products}
                                <li class="selected-product d-flex flex-wrap" data-id="{$related_product.product_id}">
                                    <span class="product-handle">&#9776;</span>                                    
                                    <div class="col-sm-11 col-md-5 col-xl-6 d-flex flex-row">
                                        <img src="{$product_images[$related_product.product_id]}" alt="{$related_product_names[$related_product.product_id]} Thumbnail" width="50" height="50" class="mr-1"> 
                                        ({$related_product_names[$related_product.product_id]}) ({$related_product.product_id})
                                    </div>
                                    <div class="col-sm-10 col-md-5 col-xl-4 d-flex flex-row">
                                    <label class="col-auto control-label align-self-center mb-0"><strong>{l s='Variant name' mod='po_linkedproduct'}</strong></label>
                                    <div class="flex-fill">
                                        <input type="hidden"  name="linking_products[{$index}][related_products][{$related_product.product_id}][product_id]" value="{$related_product.product_id}" />
                                        <input type="hidden" class="related-product-position" name="linking_products[{$index}][related_products][{$related_product.product_id}][position]" value="{if isset($related_product.position)}{$related_product.position}{else}{$smarty.foreach.related_products.iteration}{/if}" />
                                        {foreach from=$languages item=language}
                                        <div class="translatable-field lang-{$language.id_lang}" {if $language.id_lang != $default_form_language}style="display:none"{/if}>
                                            <input type="text" name="linking_products[{$index}][related_products][{$related_product.product_id}][value][{$language.id_lang}]" value="{$related_product.value[$language.id_lang]}" class="form-control">
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown">
                                                    {$language.iso_code}
                                                </button>
                                                <ul class="dropdown-menu">
                                                {foreach from=$languages item=lang}
                                                    <li><a href="javascript:hideOtherLanguage({$lang.id_lang});">{$lang.iso_code}</a></li>
                                                {/foreach}
                                                </ul>
                                            </div>
                                        </div>
                                        {/foreach}
                                    </div>
                                    </div>
                                    <span class="remove-product btn btn-danger btn-sm ml-auto" data-toggle="pstooltip" data-original-title="{l s='Delete' mod='po_linkedproduct'}"><i class="material-icons md48">delete</i></span> 
                                </li>
                            {/foreach}
                        </ul>
                    </div>
                </div>
                </div>
            </div>
        {/foreach}
            {/if}
        </div>
        <div class="form-group">
            <div class="col-sm-offset-2 col-sm-10">
                <span data-repeater-create class="btn btn-info btn-md">
                    <span class="glyphicon glyphicon-plus"></span> Dodaj nowy
                </span>
            </div>
        </div>
    </div>
</fieldset>

<script>
var languages = {$languages|json_encode};
var defaultLang = {$default_form_language};
var labelVariantName = '{l s='Variant name' mod='po_linkedproduct'}';
var defaultGroupTitle = '{$default_group_title[$default_form_language]|escape:'html':'UTF-8'}';
var newGroupText = '{l s='New group' mod='po_linkedproduct'}';
</script>
<script src="{$app_js_repeater}"></script>
<script src="{$app_js}"></script>
