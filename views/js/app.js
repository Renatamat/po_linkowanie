function createProductItem(productId, productName, productImage, number) {
  var html = '';
  html += '<li class="selected-product d-flex flex-wrap" data-id="' + productId + '">';
  html += '  <span class="product-handle ui-sortable-handle">&#9776;</span>';
  html += '  <div class="col-sm-11 col-md-5 col-xl-6 d-flex flex-row">';
  html += '    <img src="' + productImage + '" alt="' + productName + ' Thumbnail" width="50" height="50" class="mr-1">';
  html += '    (' + productName + ') (' + productId + ')';
  html += '  </div>';
  html += '  <div class="col-sm-10 col-md-5 col-xl-4 d-flex flex-row">';
  html += '    <label class="col-auto control-label align-self-center mb-0"><strong>' + labelVariantName + '</strong></label>';
  html += '    <div class="flex-fill">';
  html += '      <input type="hidden" name="linking_products[' + number + '][related_products][' + productId + '][product_id]" value="' + productId + '">';
  for (var i = 0; i < languages.length; i++) {
    var lang = languages[i];
    var style = (lang.id_lang == defaultLang) ? '' : ' style="display:none"';
    html += '      <div class="translatable-field lang-' + lang.id_lang + '"' + style + '>';
    html += '        <input type="text" name="linking_products[' + number + '][related_products][' + productId + '][value][' + lang.id_lang + ']" class="form-control">';
    html += '        <div class="btn-group">';
    html += '          <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown">' + lang.iso_code + '</button>';
    html += '          <ul class="dropdown-menu">';
    for (var j = 0; j < languages.length; j++) {
      html += '            <li><a href="javascript:hideOtherLanguage(' + languages[j].id_lang + ');">' + languages[j].iso_code + '</a></li>';
    }
    html += '          </ul>';
    html += '        </div>';
    html += '      </div>';
  }
  html += '    </div>'; // .flex-fill
  html += '  </div>';   // kolumna z label + inputs
  html += '  <span class="remove-product btn btn-danger btn-sm ml-auto" data-toggle="pstooltip" data-original-title="Delete">';
  html += '    <i class="material-icons md48">delete</i>';
  html += '  </span>';

  html += '</li>';

  return html;
}


function updateGroupTitleDisplay($item) {
  var titleInput = $item.find('input[name$="[group_title][' + defaultLang + ']"]');
  var title = titleInput.val() || defaultGroupTitle || newGroupText;
  var type = $item.find('select[name$="[type]"]').val();
  var typeLabel;
  switch (type) {
    case 'photo':
      typeLabel = 'Zdjęcie';
      break;
    case 'select':
      typeLabel = 'Lista';
      break;
    default:
      typeLabel = 'Tekst';
  }
  var iso = '';
  for (var i=0;i<languages.length;i++) {
    if (languages[i].id_lang == defaultLang) {
      iso = languages[i].iso_code;
      break;
    }
  }
  $item.find('.group-title-display').html('<strong>' + title + ' </strong> <small>[Typ wyświetlania: ' + typeLabel + '] [język: ' + iso + '] </small>');
}

function attachListeners($item) {
  $item.find('input[name$="[group_title][' + defaultLang + ']"]').on('input', function(){
    updateGroupTitleDisplay($item);
  });
  $item.find('select[name$="[type]"]').on('change', function(){
    updateGroupTitleDisplay($item);
  });
}
// --- Buforowany token administracyjny z wielu źródeł (meta, input, URL, global)
var poAdminToken;
function resolveAdminToken() {
    if (poAdminToken !== undefined) {
        return poAdminToken;
    }

    var searchParams = new URLSearchParams(window.location.search);
    poAdminToken = searchParams.get('_token')
            || searchParams.get('token')
            || (document.querySelector('meta[name="csrf-token"]') || {}).content
            || (document.querySelector('input[name="_token"]') || {}).value
            || (document.querySelector('input[name="token"]') || {}).value
            || window.token
            || (window.prestashop || {}).token
            || window.securityToken
            || null;

    return poAdminToken;
}

// --- PS 9: zbuduj URL Symfony /sell/catalog/products/search/<iso>?query=...&_token=...
function getSfSearchUrl(query) {
    var path = window.location.pathname;
    var sellIdx = path.indexOf('/sell/');
    if (sellIdx <= 0)
        return null;

    var adminBase = window.location.origin + path.slice(0, sellIdx);
    var token = resolveAdminToken();
    if (!token)
        return null;

    var iso = (document.documentElement.getAttribute('lang') || 'en').split('-')[0];
    return adminBase + '/sell/catalog/products/search/' + encodeURIComponent(iso)
            + '?query=' + encodeURIComponent(query)
            + '&_token=' + encodeURIComponent(token);
}

function getLegacyAjaxOptions(query, limit) {
    var adminToken = resolveAdminToken();
    if (!adminToken) {
        console.warn('[po_linkedproduct] Brak tokenu administracyjnego – zapytanie legacy może się nie powieść.');
    }

    return {
        url: 'index.php',
        type: 'GET',
        dataType: 'json',
        cache: false,
        data: {
            controller: 'AdminProducts',
            ajax: 1,
            action: 'productsList',
            forceJson: 1,
            disableCombination: 1,
            exclude_packs: 0,
            excludeVirtuals: 0,
            limit: limit || 15,
            token: adminToken,
            q: query
        }
    };
}
// Sprawdza, czy dany produkt jest już wybrany w tej sekcji (li.selected-product[data-id="..."])
function isSelectedInSection($targetListSetup, productId) {
  return $targetListSetup.find('.selected-product[data-id="' + productId + '"]').length > 0;
}

// --- Wspólny renderer + delegowany click (onSelect = callback po dodaniu)
function renderProductsList(productList, productListSetup, resp, onSelect) {
  var $productList = $(productList);
  var $productListSetup = $(productListSetup); // UL z wybranymi w tej sekcji
  var list = Array.isArray(resp) ? resp : (resp.products || resp.results || []);
  $productList.empty();

  // Ustal docelową listę (gdyby przekazano pustą przez wywołującego)
  if (!$productListSetup.length) {
    var searchId = $productList.data('list-id');
    if (searchId !== undefined) {
      $productListSetup = $('[data-list-setup-id="' + searchId + '"]');
    }
  }

  list.forEach(function (p) {
    var id = p.id || p.id_product || p.product_id;
    var name = p.name || p.product_name || p.product || p.display_name;
    var image = p.image || p.image_link || p.cover || '';
    if (!id || !name) return;

    // 👇 FILTR: nie pokazuj w wynikach jeśli już wybrany w tej sekcji
    if (isSelectedInSection($productListSetup, String(id))) return;

    $productList.append(
      '<li class="product-item" data-id="' + id + '">' +
        (image ? '<img src="' + image + '" alt="' + String(name).replace(/"/g, '&quot;') + ' Thumbnail" width="50" height="50"> ' : '') +
        name +
      '</li>'
    );
  });
    if ($productList.children().length === 0) {
        $productList.append(
                '<li class="no-results text-muted" style="opacity:0.7; font-style:italic; padding:5px 10px;">' +
                'Brak nowych produktów do dodania. Spróbuj wyszukać inaczej.' +
                '</li>'
                );
    }

  $productList
    .off('click.po-linked', '.product-item')
    .on('click.po-linked', '.product-item', function () {
      var $li = $(this);
      var productId   = String($li.data('id'));
      var productName = $.trim($li.clone().children().remove().end().text());
      var productImage = $li.find('img').attr('src') || '';

      // Bezpieczny lookup repeatera
      var $repeaterItem = $li.closest('[data-repeater-item], .data-repeater-item');
      if (!$repeaterItem || !$repeaterItem.length) {
        $repeaterItem = $li.parents('.data-repeater-item').first();
      }
      $repeaterItem = $($repeaterItem);
      if (!$repeaterItem.length) {
        console.warn('[po_linkedproduct] Nie znaleziono kontenera repeatera dla klikniętego wyniku.');
        return;
      }

      var $nameInput = $repeaterItem.find('input[name^="linking_products"]').first();
      var nameValue = $nameInput.attr('name') || '';
      var m = nameValue.match(/\d+/);
      var number = m ? m[0] : '';

      var repeaterItem = $repeaterItem.data('repeater-item');
      console.log(repeaterItem);
      console.log(nameValue);

      // Docelowa UL w tej sekcji
      var searchId = $li.closest('[data-list-id]').data('list-id');
      var $target = (searchId !== undefined)
        ? $('[data-list-setup-id="' + searchId + '"]')
        : $productListSetup;

      // Tryb „dobierania” dla nowej grupy:
      // włączany przy pierwszym dodaniu i utrzymywany flagą na repeaterze
      var pickModeActive = $repeaterItem.data('poNewGroupPickMode') === true
                           || $target.find('.selected-product').length === 0;

      // 🛑 Blokada duplikatów
      if (isSelectedInSection($target, productId)) {
        $li.addClass('disabled');
        setTimeout(function(){ $li.removeClass('disabled'); }, 500);
        return;
      }

      // Dodaj element do wybranych
      $target.append(createProductItem(productId, productName, productImage, number));

      // Aktualizacja licznika w repeaterze
      var selectedProducts = $repeaterItem.find('.selected-product').length;
      $repeaterItem.data('selected-counter', selectedProducts);

      if (pickModeActive) {
        // Utrzymaj tryb dobierania dla tej grupy
        $repeaterItem.data('poNewGroupPickMode', true);

        $li.addClass('disabled')
           .attr('aria-disabled', 'true')
           .attr('title', 'Produkt już dodany do tej grupy')
           .css('opacity', '0.6');
      } else {
        // Standardowe zachowanie dla edycji istniejącej grupy
        if (typeof onSelect === 'function') {
          onSelect($li, {
            id: productId,
            name: productName,
            image: productImage,
            number: number,
            target: $target
          });
        } else {
          $productList.find('.product-item').remove();
          var $localSearch = $li.closest('.input-group-search').find('.product-search');
          if ($localSearch.length) $localSearch.val('');
        }
      }
    });
}

function isValidProductsResponse(resp) {
    if (Array.isArray(resp))
        return true;
    if (resp && typeof resp === 'object') {
        if (Array.isArray(resp.products) || Array.isArray(resp.results))
            return true;
    }
    return false;
}

// --- Jedno wspólne szukanie: PS9 (Symfony) -> legacy fallback
function doProductsSearch(query, productList, productListSetup, opts) {
    opts = opts || {};
    var sfUrl = getSfSearchUrl(query);

    function renderOk(resp) {
        renderProductsList(productList, productListSetup, resp, opts.onSelect);
    }
    function runLegacy() {
        $.ajax(getLegacyAjaxOptions(query, opts.limit))
                .done(function (resp) {
                    renderOk(resp);
                })
                .fail(function (jqXHR, textStatus) {
                    console.error('[po_linkedproduct] legacy search fail:', textStatus, 'HTTP', jqXHR.status);
                });
    }

    // Jeśli nie mamy SF-URL (np. PS 1.7/8 bez /sell/) – od razu legacy
    if (!sfUrl) {
        runLegacy();
        return;
    }

    // Najpierw próbuj SF (PS 9). Na 4xx/5xx lub dziwną odpowiedź -> legacy.
    $.ajax({url: sfUrl, type: 'GET', dataType: 'json', cache: false})
            .done(function (resp) {
                if (isValidProductsResponse(resp)) {
                    renderOk(resp);
                } else {
                    // Np. HTML/komunikat – wracamy do legacy
                    runLegacy();
                }
            })
            .fail(function () {
                runLegacy();
            });
}

var repeater = $('.repeater-default').repeater({
  initval: 5,
  show: function () {
    $(this).removeClass('d-none');
    $(this)[0].style.setProperty('display', 'block', 'important');
    $(this).slideDown();
    $(this).find('.group-content').show();
    updatePositions();
    $(this).find('select[name$="[type]"]').attr('required', true).val('text');
    $(this).find('input[name$="[group_title][' + defaultLang + ']"]').attr('required', true);
    attachListeners($(this));
    updateGroupTitleDisplay($(this));
    $(this).find('.product-list-setup').sortable({
      axis: 'y',
      handle: '.product-handle',
      placeholder: 'product-placeholder',
      opacity: 0.7,
      cancel: 'input, textarea, button, select'
    });
    jQuery('.drag-positions').sortable('refresh');
  // AJAX
  $('.product-search').on('input', function() {
    var searchValue = $(this).val();
    var searchId = $(this).data('search-id');
    var productList = $('[data-list-id="' + searchId + '"]');
    var productListSetup = $('[data-list-setup-id="' + searchId + '"]');

        if (searchValue.length >= 3) {
          doProductsSearch(searchValue, productList, productListSetup, {
            limit: 15
          });
        } else {
          productList.empty();
        }

        });
    $(this).find('.product-list-setup').on('click', '.selected-product .remove-product', function() {
        $(this).closest('.selected-product').remove();
      });

      
   
  },
  hide: function (deleteElement) {
    $(this).slideUp(deleteElement, function() {
        updatePositions();
    });
  }
});
  
function updatePositions() {
    jQuery('.drag-positions [data-repeater-item]').each(function(index) {
        jQuery(this).find('input[name$="[position]"]').val(index + 1);
        updateGroupTitleDisplay(jQuery(this));
    });
}

  jQuery(".drag-positions").sortable({
      axis: "y",
      handle: ".group-handle",
      opacity: 0.5,
      placeholder: "drag-placeholder",
      delay: 150,
      update: function(event, ui) {
          $('.repeater-default').repeater('setIndexes');
          updatePositions();
      },
      cancel: 'input, textarea, button, select'
  });

  jQuery(".product-list-setup").sortable({
      axis: "y",
      handle: ".product-handle",
      placeholder: "product-placeholder",
      opacity: 0.7,
      cancel: 'input, textarea, button, select'
  });

      $(document).ready(function() {
        $('.data-repeater-item').each(function(){
          $(this).find('select[name$="[type]"]').attr('required', true);
          $(this).find('input[name$="[group_title][' + defaultLang + ']"]').attr('required', true);
          attachListeners($(this));
          updateGroupTitleDisplay($(this));
        });
        $('.product-search').on('input', function() {
          var searchValue = $(this).val();
          var searchId = $(this).data('search-id');
          var productList = $('[data-list-id="' + searchId + '"]');
          var productListSetup = $('[data-list-setup-id="' + searchId + '"]');
      
        if (searchValue.length >= 3) {
            doProductsSearch(searchValue, productList, productListSetup, {
              limit: 20,
              onSelect: function ($li, ctx) {
               
                    productList.find('.product-item').remove();
                    var $localSearch = $li.closest('.input-group-search').find('.product-search');
                    if ($localSearch.length) {
                        $localSearch.val('');
                    } else {
                        $('.product-search').val('');
                    }


                var $repeaterItemElement = $li.closest('.data-repeater-item');
                var selectedProducts = $repeaterItemElement.find('.selected-product').length;
               
              // Zapisujemy ilość selected-product dla danego data-repeater-item
                $repeaterItemElement.data('selected-counter', selectedProducts);
              }
            });
          } else {
            productList.empty();
          }
        });
      });
      $(document).on('click', '.selected-product .remove-product', function() {
        var $parent = $(this).closest('.selected-product');
        var $repeaterItemElement = $parent.closest('.data-repeater-item');
        
        $parent.remove();
        
        var selectedProducts = $repeaterItemElement.find('.selected-product').length;
      
        // Aktualizujemy licznik dla danego data-repeater-item
        $repeaterItemElement.data('selected-counter', selectedProducts);
      
        // Jeżeli to był ostatni element, usuń cały data-repeater-item
        if (selectedProducts === 0) {
          $repeaterItemElement.remove();
        }
      });

      $(document).on('click', '.toggle-group', function() {
        $(this).closest('.data-repeater-item').find('.group-content').slideToggle();
      });

      $(document).on('click', '#saveChanges', function (e) {
        e.preventDefault();
    
        var linkingProductsData = JSON.stringify($('.repeater-default').repeaterVal().products);
        var productId = $('#form_id_product').val();
    
        $('<input>').attr({
            type: 'hidden',
            id: 'linkingProductsData',
            name: 'linkingProductsData',
            value: linkingProductsData,
        }).appendTo('#form');
    
        $('<input>').attr({
            type: 'hidden',
            id: 'productId',
            name: 'id_product',
            value: productId,
        }).appendTo('#form');
    
        $('#form').submit();
    });
$(document).on('click', '.btn-reset-search', function () {
    var $wrapper = $(this).closest('.input-group-search');
    var $input = $wrapper.find('.product-search');
    $input.val('');
    $input.trigger('input'); 
    // reset trybu dobierania w najbliższym repeaterze
    var $rep = $wrapper.closest('.data-repeater-item');
    if ($rep.length)
        $rep.removeData('poNewGroupPickMode');
});

