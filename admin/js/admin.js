jQuery(document).ready(function($) {
    // Tabs
    $('.redaquest-settings .nav-tab').on('click', function(e) {
        e.preventDefault();
        var target = $(this).data('tab');

        $('.redaquest-settings .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        $('.redaquest-settings .tab-content').removeClass('active');
        $('#tab-' + target).addClass('active');

        localStorage.setItem('redaquest_active_tab', target);
    });

    var savedTab = localStorage.getItem('redaquest_active_tab');
    if (savedTab && $('.nav-tab[data-tab="' + savedTab + '"]').length) {
        $('.nav-tab[data-tab="' + savedTab + '"]').trigger('click');
    }

    // Post type checkboxes
    $('.post-type-item input[type=checkbox]').on('change', function() {
        $(this).closest('.post-type-item').toggleClass('checked', $(this).is(':checked'));
    });

    $('#redaquest-select-all-types').on('click', function() {
        $('.post-type-list input[type=checkbox]').prop('checked', true).trigger('change');
    });

    $('#redaquest-deselect-all-types').on('click', function() {
        $('.post-type-list input[type=checkbox]').prop('checked', false).trigger('change');
    });

    function renderTestResult($container, data) {
        if (!data || !data.counts) {
            $container.html('<p class="status-ok">' + redaquestAdmin.strings.testOk + '</p>');
            return;
        }

        var html = '<p class="status-ok">' + redaquestAdmin.strings.testOk + '</p><ul class="test-counts">';
        $.each(data.counts, function(type, count) {
            html += '<li><strong>' + type + ':</strong> ' + count + '</li>';
        });
        html += '</ul>';

        if (data.capabilities) {
            html += '<p class="description">';
            html += data.capabilities.write ? 'Obojsmerná sync' : 'Iba čítanie';
            if (data.capabilities.woocommerce_sync) {
                html += ' · WooCommerce zapnuté';
            }
            html += '</p>';
        }

        $container.html(html);
    }

    function runTest($btn, $result) {
        $btn.prop('disabled', true);
        $result.html('<p class="description">' + redaquestAdmin.strings.testing + '</p>');

        $.post(redaquestAdmin.ajaxUrl, {
            action: 'redaquest_test_connection',
            nonce: redaquestAdmin.nonce
        }).done(function(res) {
            if (res.success) {
                renderTestResult($result, res.data);
            } else {
                var msg = (res.data && res.data.message) ? res.data.message : redaquestAdmin.strings.testFail;
                $result.html('<p class="status-error">' + msg + '</p>');
            }
        }).fail(function() {
            $result.html('<p class="status-error">' + redaquestAdmin.strings.testFail + '</p>');
        }).always(function() {
            $btn.prop('disabled', false);
        });
    }

    $('#redaquest-test-connection, #redaquest-test-connection-diag').on('click', function() {
        var $btn = $(this);
        var $result = $btn.attr('id') === 'redaquest-test-connection-diag'
            ? $('#redaquest-test-result-diag')
            : $('#redaquest-test-result');
        runTest($btn, $result);
    });

    $('#redaquest-copy-endpoint').on('click', function() {
        var text = $('#redaquest-endpoint-url').text();
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
        } else {
            var $tmp = $('<textarea>').val(text).appendTo('body').select();
            document.execCommand('copy');
            $tmp.remove();
        }
        $(this).text(redaquestAdmin.strings.copied);
        var $btn = $(this);
        setTimeout(function() {
            $btn.text('Kopírovať');
        }, 2000);
    });

    $('#redaquest-disconnect').on('click', function() {
        if (!window.confirm(redaquestAdmin.strings.disconnectConfirm)) {
            return;
        }
        var $btn = $(this);
        $btn.prop('disabled', true).text(redaquestAdmin.strings.disconnecting);

        $.post(redaquestAdmin.ajaxUrl, {
            action: 'redaquest_disconnect',
            nonce: redaquestAdmin.nonce
        }).always(function() {
            window.location.reload();
        });
    });
});
