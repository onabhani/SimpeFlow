/* Editor binder: show manual items & allow-additions for Quality Checklist */
jQuery(function ($) {
  function isQG(field) {
    return field && field.type === 'quality_checklist';
  }

  // When a field is selected in the editor
  $(document).on('gform_load_field_settings', function (e, field) {
    if (!isQG(field)) return;

    // reveal our rows
    $('.sfa_qg_items_manual_setting, .sfa_qg_allow_additions_setting').show();

    // populate values
    $('#sfa_qg_items_manual').val(field.sfa_qg_items_manual || '');
    $('#sfa_qg_allow_additions').prop('checked', !!field.sfa_qg_allow_additions);
  });

  // persist changes to the field object
  $('#sfa_qg_items_manual').on('input', function () {
    SetFieldProperty('sfa_qg_items_manual', $(this).val());
  });

  $('#sfa_qg_allow_additions').on('change', function () {
    SetFieldProperty('sfa_qg_allow_additions', $(this).is(':checked') ? 1 : 0);
  });
});
