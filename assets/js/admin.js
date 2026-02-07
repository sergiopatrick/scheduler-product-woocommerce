(function ($) {
    function buildPayload() {
        if (typeof tinyMCE !== 'undefined') {
            tinyMCE.triggerSave();
        }

        var $postForm = $('#post');
        if (!$postForm.length) {
            return '';
        }

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

    $(function () {
        $(document).on('submit', '#sanar_wcps_schedule_form', function () {
            var payload = buildPayload();
            var $form = $(this);
            $form.find('input[name="sanar_wcps_payload"]').remove();
            $('<input>', {
                type: 'hidden',
                name: 'sanar_wcps_payload',
                value: payload
            }).appendTo($form);
        });
    });
})(jQuery);
