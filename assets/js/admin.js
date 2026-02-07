(function ($) {
    function buildPayload() {
        if (typeof tinyMCE !== 'undefined') {
            tinyMCE.triggerSave();
        }

        var $postForm = $('#post');
        if (!$postForm.length) {
            return '';
        }

        $postForm.find('input[name="sanar_wcps_payload"]').remove();
        var payload = $postForm.serialize();

        if (window.wp && wp.data && typeof wp.data.select === 'function') {
            var editor = wp.data.select('core/editor');
            if (editor) {
                var title = editor.getEditedPostAttribute('title');
                var content = editor.getEditedPostAttribute('content');
                var excerpt = editor.getEditedPostAttribute('excerpt');

                if (typeof title === 'string') {
                    payload += '&post_title=' + encodeURIComponent(title);
                }
                if (typeof content === 'string') {
                    payload += '&content=' + encodeURIComponent(content);
                }
                if (typeof excerpt === 'string') {
                    payload += '&excerpt=' + encodeURIComponent(excerpt);
                }
            }
        }

        return payload;
    }

    function updatePublishLabel() {
        var $button = $('#publish');
        if (!$button.length) {
            return;
        }

        if (!$button.data('sanar-wcps-label')) {
            $button.data('sanar-wcps-label', $button.val());
        }

        var datetime = $('#sanar_wcps_schedule_datetime').val();
        if (!datetime) {
            $button.val($button.data('sanar-wcps-label'));
            return;
        }

        var scheduledAt = new Date(datetime);
        if (!isNaN(scheduledAt.getTime()) && scheduledAt.getTime() > Date.now()) {
            $button.val('Agendar atualizacao');
            return;
        }

        $button.val($button.data('sanar-wcps-label'));
    }

    $(function () {
        $(document).on('submit', '#post', function () {
            var payload = buildPayload();
            var $form = $(this);
            $form.find('input[name="sanar_wcps_payload"]').remove();
            $('<input>', {
                type: 'hidden',
                name: 'sanar_wcps_payload',
                value: payload
            }).appendTo($form);
        });

        $(document).on('input change', '#sanar_wcps_schedule_datetime', updatePublishLabel);
        updatePublishLabel();
    });
})(jQuery);
