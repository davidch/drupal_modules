/**
 * @file
 * Javascript magic. Shows the eligible menu options when switching groups.
 */
(function ($) {
  Drupal.behaviors.og_menu = {

    attach: function() {
      // Initialize variables
      var savedTitle = '';
      var originalParent = $('.menu-parent-select').val();
      var inputTitle = $('input[name="menu[link_title]"]');
      var inputDelete = $('input[name="menu[delete]"]');
      var holder = document.createElement('select');
      var disabledOption = $(document.createElement('option'));
      disabledOption.text('--').attr('value', '').attr('class', 'value-none').prependTo('.menu-parent-select');

      // Toggle menu alteration
      function toggle(values) {
        // make sure 'values' is always an array, i.e. when using single value select
        if (!(values instanceof Array)) {
          var v = values;
          values = [];
          values[0] = v;
        }

        var title = inputTitle.val()
        var none = true;

        // Reset menu form elements
        disabledOption.remove();
        inputDelete.attr('checked', '');
        inputTitle.attr("disabled", '');

        $('.menu-parent-select option:not(.value-none)').appendTo(holder);
        $('.menu-parent-select').attr("disabled", '');

        // Handle menu link title
        if (savedTitle && !title) {
          inputTitle.val(savedTitle);
          inputTitle.trigger('change'); // Handle vertical tabs
        }
        if (title) {
          savedTitle = title;
        }

        // Enable eligible menu options. We have to move the dom options elements
        // instead of simply hiding to support webkit.
        for(var i in values) {
          //var menu = Drupal.settings.og_menu[values[i]];
          $('option', holder).each(function() {
            parts = $(this).val().split(':');
            if (Drupal.settings.og_menu[parts[0]] == values[i]) {
              $(this).appendTo('.menu-parent-select');
              none = false;
            }
          });
        }
        // No option is eligible, disable the menu select
        if (none) {
          disabledOption.appendTo('.menu-parent-select');
          inputTitle.val('');
          inputTitle.attr("disabled", "disabled");
          inputTitle.trigger('change');
          inputDelete.attr('checked', 'checked');
          $('.menu-parent-select').attr("disabled", "disabled");
          $('.menu-parent-select').val('');
        }
        else {
          // If an option exists with the initial value, set it. We do this because
          // we want to keep the original parent if user just adds a group to the node.
          if ($('.menu-parent-select option[value='+originalParent+']')) {
            $('.menu-parent-select').val(originalParent);
          }
        }
      }

      // Toggle function for OG select
      var toggleSelect = function() {
        toggle($(this).val());
      };

      // Alter menu on OG select change and init
      if ($('select.group-audience').size()) {
        $('select.group-audience').change(toggleSelect).ready(toggleSelect);
      }

      // init
      toggle($('select.group-audience').val());
    }

  };
}(jQuery));