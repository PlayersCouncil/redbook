/* global app, _, Translator */
/**
 * The bug report form (Ketura, 2026-11-09): opened from the top bar, or anything marked
 * data-feedback. Each time it opens it fills in the page it was opened on and, if the name is
 * empty, the logged-in user's name; both stay editable. Sent as JSON; the message is the
 * site's markdown editor.
 */
(function app_feedback(feedback, $)
{
    function t(key, params) { return Translator.trans(key, params || {}); }

    /**
     * "desktop (1920x1080)", "tablet (820x1180)" or "phone (390x844)": the screen's size in
     * CSS pixels, and a touch screen's kind by its shorter side
     */
    feedback.device = function device()
    {
        var w = window.screen ? window.screen.width : window.innerWidth,
            h = window.screen ? window.screen.height : window.innerHeight,
            touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches,
            kind = !touch ? 'desktop' : (Math.min(w, h) < 600 ? 'phone' : 'tablet');
        return kind + ' (' + w + 'x' + h + ')';
    };

    feedback.open = function open()
    {
        var modal = $('#feedback-modal');
        if(!modal.length) {
            return;
        }
        var form = modal.find('form');
        form.find('[name=page]').val(window.location.href);
        // the browser and the screen it's on (Ketura, 2026-11-11), theirs to correct
        var browser = form.find('[name=browser]'), device = form.find('[name=device]');
        if(!$.trim(browser.val())) {
            browser.val(navigator.userAgent || '');
        }
        if(!$.trim(device.val())) {
            device.val(feedback.device());
        }
        var name = form.find('[name=name]');
        if(!$.trim(name.val()) && app.user && app.user.data) {
            name.val(app.user.data.display_name || app.user.data.name || '');
        }
        form.find('.rb-feedback-status').text('').removeClass('is-error is-sent');
        form.find('.rb-feedback-fields').prop('hidden', false);
        form.find('[type=submit]').prop('hidden', false).prop('disabled', false);
        modal.modal('show');
        app.md_editor && app.md_editor.attach('#feedback-message', {footer: t('feedback.editor_hint')});
    };

    $(function () {
        $(document).on('click', '[data-feedback]', function (event) {
            event.preventDefault();
            feedback.open();
        });
        $(document).on('shown.bs.modal', '#feedback-modal', function () {
            var form = $(this).find('form'), first = form.find('[name=name]');
            ($.trim(first.val()) ? form.find('#feedback-message') : first).focus();
        });
        $(document).on('submit', '#feedback-form', function (event) {
            event.preventDefault();
            var form = $(this), status = form.find('.rb-feedback-status'), button = form.find('[type=submit]');
            if(!$.trim(form.find('#feedback-message').val())) {
                status.text(t('feedback.error.empty')).addClass('is-error');
                return;
            }
            button.prop('disabled', true);
            status.text(t('feedback.sending')).removeClass('is-error');
            $.ajax(form.attr('action'), {type: 'POST', data: form.serialize(), dataType: 'json'})
                .done(function (response) {
                    status.text(response.message || t('feedback.sent')).addClass('is-sent');
                    form.find('.rb-feedback-fields').prop('hidden', true);
                    button.prop('hidden', true);
                    form.find('#feedback-message').val('').trigger('input');
                })
                .fail(function (xhr) {
                    var response = xhr.responseJSON || {};
                    status.text(response.message || t('feedback.error.failed')).addClass('is-error');
                    button.prop('disabled', false);
                });
        });
    });
})(app.feedback = {}, jQuery);
