jQuery(document).ready(function ($) {
    $('#event-registration-form').on('submit', function (e) {
        e.preventDefault();

        var formData = $(this).serialize();

        $.ajax({
            url: eventRegistrationAjax.ajax_url,
            type: 'POST',
            data: formData,
            success: function (response) {
                if (response.success) {
                    alert('Успешна регистрация');
                    $('#event-registration-form')[0].reset();
                } else {
                    alert('Грешка при регистрацията: ' + (response.data || 'Неизвестна грешка'));
                }
            },
            error: function () {
                alert('Възникна техническа грешка. Моля, опитайте отново.');
            },
        });
    });
});
