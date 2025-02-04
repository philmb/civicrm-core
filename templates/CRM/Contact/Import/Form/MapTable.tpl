{*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
*}
<div class="crm-block crm-form-block crm-import-maptable-form-block">

{* Import Wizard - Data Mapping table used by MapFields.tpl and Preview.tpl *}
 <div id="map-field">
    {strip}
    <table class="selector">
    {if $savedMappingName}
        <tr class="columnheader-dark"><th colspan="4">{ts 1=$savedMappingName}Saved Field Mapping: %1{/ts}</td></tr>
    {/if}
        <tr class="columnheader">
      {if $showColNames}
          {assign var="totalRowsDisplay" value=$rowDisplayCount+1}
      {else}
          {assign var="totalRowsDisplay" value=$rowDisplayCount}
      {/if}
            {section name=rows loop=$totalRowsDisplay}
                {if $smarty.section.rows.iteration == 1 and $showColNames}
                  <td>{ts}Column Names{/ts}</td>
                {elseif $showColNames}
                  <td>{ts 1=$smarty.section.rows.iteration-1}Import Data (row %1){/ts}</td>
    {else}
      <td>{ts 1=$smarty.section.rows.iteration}Import Data (row %1){/ts}</td>
                {/if}
            {/section}

            <td>{ts}Matching CiviCRM Field{/ts}</td>
        </tr>

        {*Loop on columns parsed from the import data rows*}
        {section name=cols loop=$columnCount}
            {assign var="i" value=$smarty.section.cols.index}
            <tr style="border: 1px solid #DDDDDD;">

                {if $showColNames}
                    <td class="even-row labels">{$columnNames[$i]}</td>
                {/if}

                {section name=rows loop=$rowDisplayCount}
                    {assign var="j" value=$smarty.section.rows.index}
                    <td class="odd-row">{$dataValues[$j][$i]|escape}</td>
                {/section}

                {* Display mapper <select> field for 'Map Fields', and mapper value for 'Preview' *}
                <td class="form-item even-row{if $wizard.currentStepName == 'Preview'} labels{/if}">
                    {if $wizard.currentStepName == 'Preview'}
                    {if $relatedContactDetails && $relatedContactDetails[$i] != ''}
                            {$mapper[$i]} - {$relatedContactDetails[$i]}

                            {if $relatedContactLocType && $relatedContactLocType[$i] != ''}
                              - {$relatedContactLocType[$i]}
                      {/if}

                            {if $relatedContactPhoneType && $relatedContactPhoneType[$i] != ''}
                              - {$relatedContactPhoneType[$i]}
                      {/if}

                            {* append IM Service Provider type for related contact *}
                            {if  $relatedContactImProvider && $relatedContactImProvider[$i] != ''}
                                - {$relatedContactImProvider[$i]}
                            {/if}

          {* append website type *}
          {if  $relatedContactWebsiteType && $relatedContactWebsiteType[$i] != ''}
                                - {$relatedContactWebsiteType[$i]}
                            {/if}

          {else}

                            {if $locations[$i]}
                                {$locations[$i]} -
                            {/if}

                            {if $phones[$i]}
                                {$phones[$i]} -
                            {/if}

                            {* append IM Service provider type for contact *}
                            {if $ims[$i]}
                                {$ims[$i]} -
                            {/if}

          {* append website type *}
                            {if $websites[$i]}
                                {$websites[$i]} -
                            {/if}

                            {*else*}
                                {$mapper[$i]}
                            {*/if*}
                        {/if}
                    {else}
                        {$form.mapper[$i].html|smarty:nodefaults}
                    {/if}
                </td>

            </tr>
        {/section}

    </table>
  {/strip}

    {if $wizard.currentStepName != 'Preview'}
    <div>

      {if $savedMappingName}
          <span>{$form.updateMapping.html} &nbsp;&nbsp; {$form.updateMapping.label}</span>
      {/if}
      <span>{$form.saveMapping.html} &nbsp;&nbsp; {$form.saveMapping.label}</span>
      <div id="saveDetails" class="form-item">
            <table class="form-layout-compressed">
            <tr class="crm-import-maptable-form-block-saveMappingName">
                        <td class="label">{$form.saveMappingName.label}</td>
                        <td>{$form.saveMappingName.html}</td>
                    </tr>
            <tr class="crm-import-maptable-form-block-saveMappingName">
                        <td class="label">{$form.saveMappingDesc.label}</td>
                        <td>{$form.saveMappingDesc.html}</td>
                    </tr>
            </table>
      </div>
      <script type="text/javascript">
             {if $mappingDetailsError }
                cj('#saveDetails').show();
             {else}
              cj('#saveDetails').hide();
             {/if}

           {literal}
            function showSaveDetails(chkbox) {
             if (chkbox.checked) {
              document.getElementById("saveDetails").style.display = "block";
              document.getElementById("saveMappingName").disabled = false;
              document.getElementById("saveMappingDesc").disabled = false;
             } else {
              document.getElementById("saveDetails").style.display = "none";
              document.getElementById("saveMappingName").disabled = true;
              document.getElementById("saveMappingDesc").disabled = true;
             }
             }
            cj('select[id^="mapper"][id$="[0]"]').addClass('huge');
            {/literal}
      {include file="CRM/common/highLightImport.tpl" relationship=true}

      {* // Set default location type *}
      {literal}
      CRM.$(function($) {
        var defaultLocationType = "{/literal}{$defaultLocationType}{literal}";
        if (defaultLocationType.length) {
          $('#map-field').on('change', 'select[id^="mapper"][id$="_0"]', function() {
            var select = $(this).next();
            $('option', select).each(function() {
              if ($(this).attr('value') == defaultLocationType
                && $(this).text() == {/literal}{$defaultLocationTypeLabel|@json_encode}{literal}) {
                select.val(defaultLocationType);
              }
            });
          });
        }
      });
      {/literal}
  </script>
    </div>
    {/if}
 </div>
</div>
