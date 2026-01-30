{if $positions}
    <div class="product-information ">
        <div class="col-12">
            <div class="product-linked">
                {foreach $positions as $position}
                      <div class="mb-6">
                           <p class="type-container">{if isset($position.group_title[$id_lang])}{$position.group_title[$id_lang]}{/if}</p>                         
                            {if $position.type == 'text'}
                                {assign var="related_products" value=$position.related_products}                       
                                      <ul class="type-list type-list-text">
                                        {foreach $related_products as $related_product}
                                            {assign var="product_link" value=$link->getProductLink($related_product.product_id)}
                                            <li>
                                                {if $related_product.disabled}
                                                    <span class="type-list-text">{$related_product.display_value}</span>
                                                {else}
                                                    <a rel="{if $product_link == $page.canonical}nofollow{/if}" href="{$product_link}" class="{if $product_link == $page.canonical}active{/if}  type-list-text">
                                                    {$related_product.display_value}
                                                    </a>
                                                {/if}
                                            </li>
                                        {/foreach}
                                    </ul>                       
                                {/if}
                              {if $position.type == 'photo'}
                                  {assign var="related_products" value=$position.related_products}
                                   <ul class="type-list type-list-photo">
                                          {foreach $related_products as $related_product}
                                              {assign var="product_link" value=$link->getProductLink($related_product.product_id)}
                                              <li class="">
                                                <a rel="{if $product_link == $page.canonical}nofollow{/if}" href="{$product_link}" class="{if $product_link == $page.canonical}active{/if} type-list-photo info" title="{$related_product.display_value}">
                                                    <img src="{$related_product_images[$related_product.product_id]}" alt="{$related_product.display_value} image" class="product-thumbnail">
                                                    <span class="variant-name product-title">{$related_product.display_value}</span>
                                                </a>
                                              </li>
                                          {/foreach}
                                      </ul>                         
                              {/if}
                              {if $position.type == 'select'}
                                  {assign var="related_products" value=$position.related_products}
                                  <div class="type-select-container">
                                        <select class="type-select form-control form-control-select">
                                            {foreach $related_products as $related_product}
                                                {assign var="product_link" value=$link->getProductLink($related_product.product_id)}
                                                <option value="{$product_link}" {if $product_link == $page.canonical}selected{/if}{if $related_product.disabled} disabled{/if}>{$related_product.display_value}</option>
                                            {/foreach}
                                        </select>
                                      </div>                          
                              {/if} 
                      </div>
                  {/foreach}
              </div>
          </div>
      </div>{/if}
