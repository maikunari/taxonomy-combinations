/**
 * Admin JavaScript for Taxonomy Combination Plugin
 */

jQuery(document).ready(function($) {
    
    // Tab Navigation
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        
        var target = $(this).attr('href');
        
        // Update active tab
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        // Show target content
        $('.tab-content').hide();
        $(target).show();
        
        // Save active tab to localStorage
        if (typeof(Storage) !== "undefined") {
            localStorage.setItem('tc_active_tab', target);
        }
    });
    
    // Restore active tab
    if (typeof(Storage) !== "undefined") {
        var activeTab = localStorage.getItem('tc_active_tab');
        if (activeTab && $(activeTab).length) {
            $('.nav-tab[href="' + activeTab + '"]').trigger('click');
        }
    }
    
    // Character Counters
    $('#meta_title').on('input', function() {
        var length = $(this).val().length;
        $('#title-length').text(length);
        
        if (length > 60) {
            $(this).addClass('over-limit');
        } else {
            $(this).removeClass('over-limit');
        }
    });
    
    $('#meta_description').on('input', function() {
        var length = $(this).val().length;
        $('#desc-length').text(length);
        
        if (length > 160) {
            $(this).addClass('over-limit');
        } else {
            $(this).removeClass('over-limit');
        }
    });
    
    // Select All Checkbox
    $('#cb-select-all').on('change', function() {
        var isChecked = $(this).prop('checked');
        $('input[name="combo_ids[]"]').prop('checked', isChecked);
    });
    
    // Individual checkbox change
    $('input[name="combo_ids[]"]').on('change', function() {
        var total = $('input[name="combo_ids[]"]').length;
        var checked = $('input[name="combo_ids[]"]:checked').length;
        
        $('#cb-select-all').prop('checked', total === checked);
    });
    
    // Bulk Actions
    $('#bulk-apply').on('click', function(e) {
        e.preventDefault();
        
        var action = $('#bulk-action').val();
        var checked = $('input[name="combo_ids[]"]:checked');
        
        if (!action) {
            alert('Please select a bulk action');
            return;
        }
        
        if (checked.length === 0) {
            alert('Please select at least one combination');
            return;
        }
        
        if (!confirm('Apply this action to ' + checked.length + ' combinations?')) {
            return;
        }
        
        // Get selected IDs
        var ids = [];
        checked.each(function() {
            ids.push($(this).val());
        });
        
        // Show loading state
        $(this).addClass('tc-loading').prop('disabled', true);
        
        // AJAX request
        $.post(tc_ajax.ajax_url, {
            action: 'tc_bulk_update',
            nonce: tc_ajax.nonce,
            combo_ids: ids,
            bulk_action: action,
            bulk_value: $('#bulk-value').val()
        }, function(response) {
            if (response.success) {
                alert(response.data.message);
                location.reload();
            } else {
                alert('Error: ' + response.data);
            }
        }).always(function() {
            $('#bulk-apply').removeClass('tc-loading').prop('disabled', false);
        });
    });
    
    // Filter form enhancement
    $('.tablenav select, .tablenav input[type="text"]').on('change', function() {
        var $form = $(this).closest('form');
        if ($(this).is('select')) {
            // Auto-submit on select change
            if ($(this).val() !== '') {
                $form.find('input[type="submit"]').addClass('tc-loading');
            }
        }
    });
    
    // Content Block Preview
    $('select[name="content_block_id"], select[name="header_content_block_id"], select[name="footer_content_block_id"]').on('change', function() {
        var blockId = $(this).val();
        var $preview = $(this).siblings('.block-preview');
        
        if (!blockId) {
            $preview.remove();
            return;
        }
        
        // You could add AJAX preview here if needed
        if (!$preview.length) {
            $(this).after('<div class="block-preview"><a href="' + 
                         tc_ajax.edit_url + '?post=' + blockId + 
                         '&action=edit" target="_blank" class="button button-small">Edit Block</a></div>');
        }
    });
    
    // Confirm navigation away if form changed
    var formChanged = false;
    
    $('form input, form select, form textarea').on('change', function() {
        formChanged = true;
    });
    
    $('form').on('submit', function() {
        formChanged = false;
    });
    
    $(window).on('beforeunload', function() {
        if (formChanged) {
            return 'You have unsaved changes. Are you sure you want to leave?';
        }
    });
    
    // Quick Edit functionality
    $('.quick-edit').on('click', function(e) {
        e.preventDefault();
        
        var $row = $(this).closest('tr');
        var id = $row.data('id');
        
        // Toggle quick edit row
        var $editRow = $('#quick-edit-' + id);
        if ($editRow.length) {
            $editRow.toggle();
        } else {
            // Load quick edit form via AJAX
            loadQuickEditForm(id, $row);
        }
    });
    
    // Load Quick Edit Form
    function loadQuickEditForm(id, $row) {
        $.post(tc_ajax.ajax_url, {
            action: 'tc_get_quick_edit',
            nonce: tc_ajax.nonce,
            combo_id: id
        }, function(response) {
            if (response.success) {
                $row.after(response.data.html);
            }
        });
    }
    
    // Save Quick Edit
    $(document).on('click', '.save-quick-edit', function(e) {
        e.preventDefault();
        
        var $form = $(this).closest('form');
        var data = $form.serialize();
        
        $.post(tc_ajax.ajax_url, data + '&action=tc_save_quick_edit&nonce=' + tc_ajax.nonce, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error saving changes');
            }
        });
    });
    
    // Cancel Quick Edit
    $(document).on('click', '.cancel-quick-edit', function(e) {
        e.preventDefault();
        $(this).closest('tr').remove();
    });
    
    // Tooltips
    $('.help-tip').on('mouseenter', function() {
        var $tip = $('<div class="tc-tooltip">' + $(this).data('tip') + '</div>');
        $('body').append($tip);
        
        var offset = $(this).offset();
        $tip.css({
            top: offset.top - $tip.outerHeight() - 5,
            left: offset.left - ($tip.outerWidth() / 2) + ($(this).outerWidth() / 2)
        }).fadeIn(200);
    }).on('mouseleave', function() {
        $('.tc-tooltip').remove();
    });
    
    // Live Search for combinations
    var searchTimer;
    $('#combo-search').on('keyup', function() {
        clearTimeout(searchTimer);
        var query = $(this).val();
        
        if (query.length < 2) {
            $('#search-results').empty();
            return;
        }
        
        searchTimer = setTimeout(function() {
            $.post(tc_ajax.ajax_url, {
                action: 'tc_search_combinations',
                nonce: tc_ajax.nonce,
                search: query
            }, function(response) {
                if (response.success) {
                    displaySearchResults(response.data);
                }
            });
        }, 300);
    });
    
    // Display Search Results
    function displaySearchResults(results) {
        var $container = $('#search-results');
        $container.empty();
        
        if (results.length === 0) {
            $container.html('<p>No results found</p>');
            return;
        }
        
        var html = '<ul>';
        $.each(results, function(i, item) {
            html += '<li><a href="?page=taxonomy-combinations&edit=' + item.id + '">' +
                   item.specialty_name + ' in ' + item.location_name + '</a></li>';
        });
        html += '</ul>';
        
        $container.html(html);
    }
    
    // Keyboard Shortcuts
    $(document).on('keydown', function(e) {
        // Ctrl+S to save
        if ((e.ctrlKey || e.metaKey) && e.which === 83) {
            e.preventDefault();
            $('form .button-primary').trigger('click');
        }
        
        // ESC to cancel
        if (e.which === 27) {
            $('.cancel-quick-edit').trigger('click');
        }
    });
    
    // Responsive Table Labels
    if ($(window).width() <= 782) {
        $('.wp-list-table td').each(function() {
            var $th = $(this).closest('table').find('th').eq($(this).index());
            $(this).attr('data-label', $th.text());
        });
    }
    
    // Initialize any select2 fields if available
    if ($.fn.select2) {
        $('select[multiple]').select2({
            placeholder: 'Select options...',
            allowClear: true
        });
    }
    
});