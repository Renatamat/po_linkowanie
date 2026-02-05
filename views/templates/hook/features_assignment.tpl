<fieldset>
    <div class="panel panel-default">
        <div class="panel-heading">{l s='Łączenie po cechach' mod='po_linkedproduct_features'}</div>
        <div class="panel-body">
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='Profil linkowania' mod='po_linkedproduct_features'}</label>
                <div class="col-lg-9">
                    <select name="po_link_profile_id" class="form-control">
                        <option value="0">{l s='Brak' mod='po_linkedproduct_features'}</option>
                        {foreach from=$feature_profiles item=profile}
                            <option value="{$profile.id_profile}"{if $feature_assignment.id_profile == $profile.id_profile} selected{/if}>
                                {$profile.name|escape:'html':'UTF-8'}
                            </option>
                        {/foreach}
                    </select>
                    <p class="help-block">{l s='Wybierz profil, aby aktywować linkowanie po cechach dla produktu.' mod='po_linkedproduct_features'}</p>
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='Kod rodziny / prefiks referencji' mod='po_linkedproduct_features'}</label>
                <div class="col-lg-9">
                    <input type="text" name="po_link_family_key" class="form-control" value="{$feature_assignment.family_key|escape:'html':'UTF-8'}">
                    <p class="help-block">{l s='Prefix referencji (np. SM-PR01), aby połączyć wszystkie produkty z referencją zaczynającą się od tej wartości.' mod='po_linkedproduct_features'}</p>
                </div>
            </div>
        </div>
    </div>
</fieldset>
