(function ($) {
    'use strict';

    function setMessage(text, isError) {
        var $msg = $('#redaquest-approval-message');
        $msg.text(text || '');
        $msg.toggleClass('notice notice-error', !!isError);
        $msg.toggleClass('notice notice-success', !isError && !!text);
    }

    function setStatus(status) {
        var labels = {
            waiting: 'Čaká na schválenie',
            approved: 'Schválené',
            rejected: 'Zamietnuté',
        };
        $('#redaquest-approval-status').text(labels[status] || 'Neodoslané');
    }

    $(document).on('click', '#redaquest-submit-approval', function () {
        var $box = $('.redaquest-metabox');
        var postId = $box.data('post-id');
        var $btn = $(this);

        if (!postId) {
            return;
        }

        $btn.prop('disabled', true);
        setMessage(redaquestPostMetabox.strings.submitting, false);

        $.post(redaquestPostMetabox.ajaxUrl, {
            action: 'redaquest_submit_approval',
            nonce: redaquestPostMetabox.nonce,
            post_id: postId,
        })
            .done(function (response) {
                if (response.success) {
                    setStatus(response.data.approval_status || 'waiting');
                    setMessage(redaquestPostMetabox.strings.submitSuccess, false);
                } else {
                    setMessage(response.data && response.data.message ? response.data.message : redaquestPostMetabox.strings.submitFail, true);
                }
            })
            .fail(function () {
                setMessage(redaquestPostMetabox.strings.submitFail, true);
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    });
})(jQuery);
