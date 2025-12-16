$(document).ready(function() {
    $('.product-linked').on('change', 'select.type-select', function() {
        var url = $(this).val();
        if (url) {
            window.location.href = url;
        }
    });
});
