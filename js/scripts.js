jQuery(document).ready(function($) {
    // Handle tab switching
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        var tab_id = $(this).data('tab');
        
        $('.nav-tab').removeClass('nav-tab-active');
        $('.tab-content').removeClass('active');
        
        $(this).addClass('nav-tab-active');
        $('#' + tab_id + '-content').addClass('active');
    });

    // Handle scan button click
    $('#cta-scan-button').on('click', function() {
        var $button = $(this);
        var $status = $('#cta-scan-status');

        $button.prop('disabled', true);
        $status.text('Escaneando...');

        function scanBatch(offset) {
            $.post(cta_ajax.ajax_url, {
                action: 'cta_scan_images',
                offset: offset
            }, function(response) {
                if (response.success) {
                    if (response.data.completed) {
                        $status.text('Escaneo completado.');
                        $button.prop('disabled', false);
                    } else {
                        $status.text('Escaneando... Offset: ' + response.data.offset);
                        scanBatch(response.data.offset);
                    }
                } else {
                    $status.text('Error durante el escaneo.');
                    $button.prop('disabled', false);
                }
            });
        }

        scanBatch(0);
    });

    // Load results on page load
    function loadResults(page, filterNoAlt) {
        $.post(cta_ajax.ajax_url, {
            action: 'cta_load_results',
            paged: page,
            filter_no_alt: filterNoAlt ? 1 : 0
        }, function(response) {
            if (response.success) {
                $('#cta-results-table-body').html(response.data.content);
                setupPagination(response.data.paged, response.data.total_pages);
            }
        });
    }

    function setupPagination(currentPage, totalPages) {
        $('.cta-pagination').html('');
        for (var i = 1; i <= totalPages; i++) {
            var $link = $('<a href="#">' + i + '</a>');
            if (i == currentPage) {
                $link.css('font-weight', 'bold');
            }
            $link.on('click', function(e) {
                e.preventDefault();
                loadResults($(this).text(), $('#filter-no-alt').is(':checked'));
            });
            $('.cta-pagination').append($link);
        }
    }

    loadResults(1, false);

    // Handle alt text update
    $(document).on('blur', '.cta-new-alt-text', function() {
        var $input = $(this);
        var imageId = $input.data('image-id');
        var newAltText = $input.val();

        $.post(cta_ajax.ajax_url, {
            action: 'cta_update_alt_text',
            image_id: imageId,
            new_alt_text: newAltText
        }, function(response) {
            if (response.success) {
                alert('Texto alternativo actualizado.');
            } else {
                alert('Error al actualizar el texto alternativo.');
            }
        });
    });

    // Handle filter checkbox
    $('#filter-no-alt').on('change', function() {
        loadResults(1, $(this).is(':checked'));
    });
});
