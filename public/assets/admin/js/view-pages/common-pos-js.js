     "use strict";
        $("#customer").change(function() {
            if($(this).val())
            {
                $('#customer_data').removeClass('d-none').addClass('d-flex');
                $('#Home_a').prop('disabled', false);

                $('#wallet_payment').prop('disabled', false);
                $('#wallet_payment_li').removeClass('d-none');

                $('#Dine_a').prop('disabled', false);
                $('#customer_id').val($(this).val());


                $.get({
                url: $(this).data('url'),
                dataType: 'json',
                data: {
                    customer_id: $(this).val()
                },
                beforeSend: function () {
                    $('#loading').show();
                },
                success: function (data) {
                    $('#customer_name').text(data.user.customer_name );
                    $('#contact_person_name').val(data.user.customer_name );
                    $('#contact_person_number').val(data.user.contact_person_number);
                    $('#customer_phone').text(data.user.customer_phone );
                    $('#customer_wallet').text(data.user.customer_wallet );
                    $('#customer_email').text(data.user.customer_email );
                    $('#customer_data').removeClass('d-none').addClass('d-flex');
                    $('#delivery_address_div').empty().html(data.view);

                },
                complete: function () {
                    $('#loading').hide();
                },
            });
            } else {

            $.get({
                url: $(this).data('clear-data-url'),
                dataType: 'json',
                success: function (data) {
                    $('#delivery_address_div').empty().addClass('d-none');
                    $('#customer_data').addClass('d-none').removeClass('d-flex');
                    $('#Home_a').prop('disabled', true).prop('checked', false);
                    $('#Dine_a').prop('disabled', true).prop('checked', false);
                    $('#wallet_payment').prop('disabled', true).prop('checked', false);
                    $('#wallet_payment_li').addClass('d-none');
                    $('#take_a').prop('checked', true);

                    handleOrderType($('[name="order_type"]:checked'));
                },
            });
            }
        }).trigger('change');


        $(document).on('click', '.call-map-init', function () {
            let name = $('#customer_name').text().trim();
            let phone = $('#customer_phone').text().trim();
            phone = phone.replace(/^\(|\)$/g, '');

            $('#contact_person_name').val(name);
            $('#contact_person_number').val(phone);
            initMap();
            initTelInputs();
        });

        $(document).on('click', '.clear-when-done', function (e) {
            e.preventDefault();

            $('#delivery_address_store')
                .find('input, select, textarea')
                .each(function () {
                    let $field = $(this);
                    let type = $field.attr('type');
                    let name = $field.attr('name');
                    if (name === 'contact_person_name' || name === 'contact_person_number') {
                        return;
                    }

                    if ($field.is('select')) {
                        $field.prop('selectedIndex', 0);
                    } else if (type === 'checkbox' || type === 'radio') {
                        $field.prop('checked', false);
                    } else {
                        $field.val('');
                    }
                });
        });


        $(document).on('click', '.place-order-submit', function () {
           $(this).prop('disabled', true);
            $('#order_place').submit();
        });



        function handleOrderType($element) {
            if ($element.val() === 'delivery') {
                getUserAddress($element.data('user-address-url'));
            } else {
                $('#delivery_address_div').addClass('d-none');
            }

            $.get({
                url: $element.data('url'),
                dataType: 'json',
                data: {
                    type: $element.val(),
                },
                success: function (data) {
                    updateCart();
                    if ($element.val() === 'delivery' && typeof loadDeliveryTypes === 'function') {
                        loadDeliveryTypes();
                    }
                }
            });
        }

        $(document).on('click', '[name="order_type"]', function () {
            handleOrderType($(this));
        });


        function getUserAddress(url){
            $.get({
                url: url,
                dataType: 'json',
                data: {
                    customer_id: $('#customer').val(),
                },
                beforeSend: function () {
                    $('#loading').show();
                },
                success: function (data) {
                    $('#delivery_address_div').empty().html(data.view);
                    $('#delivery_address_div').removeClass('d-none');
                },
                complete: function () {
                    $('#loading').hide();
                },
            });

        }

        $(document).on('click', '.editAddress', function () {
            $.get({
                url: $(this).data('url'),
                dataType: 'json',
                data: {
                    customer_id: $('#customer').val(),
                    address_id: $(this).data('address_id'),
                },
                beforeSend: function () {
                    $('#loading').show();
                },
                success: function (data) {

                    $('#show-address-modal').empty().html(data.view);
                    $('#paymentModal').modal('show');
                    initMap(data.latitude, data.longitude);
                       initTelInputs();

                },
                complete: function () {
                    $('#loading').hide();
                },
            });
        });

        $(document).on('click', '.choose-address', function () {
            chooseAddress($(this).data('url'),$('#customer').val(),$(this).data('id'));
        });

        function chooseAddress(url,customer_id,address_id){
            console.log(url,customer_id,address_id);
             $.get({
                url:  url,
                dataType: 'json',
                data: {
                    customer_id: customer_id,
                    address_id: address_id,
                },
                beforeSend: function () {
                    $('#loading').show();
                },
                success: function (data) {
                   updateCart();
                    $('#delivery_address_div').empty().html(data.view);
                    $('#delivery_address_div').removeClass('d-none');
                },
                complete: function () {
                    $('#loading').hide();
                },
            });
        }
        $(document).on('submit', '#customer-add-form', function (event) {
            event.preventDefault();
            if(document.getElementById('phone').value.length <= 4){
                    toastr.error($(this).data('warning-message'), {
                    CloseButton: true,
                    ProgressBar: true
                });
                }
                else{
                    document.getElementById('customer-add-form').submit();
                }
        });
