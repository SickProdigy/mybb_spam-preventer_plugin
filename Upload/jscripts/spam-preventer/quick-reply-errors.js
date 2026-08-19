(function($) {
    'use strict';

    var alertId = 'spam-preventer-quick-reply-error';

    function removeAlert() {
        $('#' + alertId).remove();
    }

    function showAlert(messages) {
        var form = $('#quick_reply_form');

        if (!form.length || !messages.length) {
            return;
        }

        removeAlert();

        var alert = $('<div>', {
            id: alertId,
            'class': 'red_alert',
            role: 'alert'
        });

        if (messages.length === 1) {
            alert.text(messages[0]);
        } else {
            var list = $('<ul>');
            $.each(messages, function(index, message) {
                $('<li>').text(message).appendTo(list);
            });
            alert.append(list);
        }

        alert.hide().insertBefore(form).slideDown('fast');
        alert.get(0).scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }

    $(document).ajaxComplete(function(event, request, settings) {
        if (!settings.url || settings.url.indexOf('newreply.php?ajax=1') === -1) {
            return;
        }

        var response;

        try {
            response = JSON.parse(request.responseText);
        } catch (error) {
            return;
        }

        if (response && $.isArray(response.errors) && response.errors.length) {
            $('.jGrowl').jGrowl('close');
            showAlert(response.errors);
        } else {
            removeAlert();
        }
    });

    $(function() {
        $('#message').on('input', removeAlert);
    });
})(jQuery);
