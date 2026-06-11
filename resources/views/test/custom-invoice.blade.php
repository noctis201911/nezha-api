@extends('layouts.admin.app')
@section('title', translate('messages.Invoice_Setup'))

@section('content')

    <div class="content">
        <form action="">
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1 class="page-header-title text-capitalize">
                            {{translate('messages.Invoice_Setup') }}
                        </h1>
                    </div>
                </div>
                <!-- End Page Header -->

                <div class="d-flex flex-wrap gap-3 justify-content-center align-items-start">
                    <div class="card flex-grow-1">
                        <div class="card-header d-block">
                            <h3 class="mb-1">{{ translate('messages.Editor') }}</h3>
                            <p class="fs-12 mb-0">{{ translate('messages.Manage the following template, edit and view the changes made.') }}</p>
                        </div>
                        <div class="card-body">
                            <div class="card card-body mb-20">
                                <div class="view-details-container">
                                    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap flex-sm-nowrap">
                                        <div>
                                            <h4 class="mb-1">{{ translate('messages.Logo on invoice') }}</h4>
                                            <p class="mb-0 fs-12">
                                                {{ translate('messages.Enable the option to update the logo that appears on invoices') }}
                                            </p>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <div
                                                class="view-btn active order-sm-0 order-3 fz--14px text-primary cursor-pointer text-decoration-underline font-semibold d-flex align-items-center gap-1">
                                                {{ translate('messages.view') }}
                                                <i class="tio-chevron-down"></i>
                                            </div>
                                            <label class="toggle-switch toggle-switch-sm m-0">
                                                <input type="checkbox" class="toggle-switch-input" name="" id="logo-switch" data-target="#preview-logo" value="">
                                                <span class="toggle-switch-label">
                                                    <div class="toggle-switch-indicator"></div>
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="view-details mt-3 d-block">
                                        <div class="__bg-F8F9FC-card">
                                            <div class="mb-20">
                                                <label class="input-label text-capitalize d-inline-flex align-items-center">
                                                    {{ translate('messages.Choose how to display the logo') }}
                                                </label>
                                                <div class="resturant-type-group border bg-white">
                                                    <label class="form-check form--check mr-2 mr-md-4">
                                                        <input class="form-check-input" type="radio" value="" name="display_logo" id="business_logo" checked="">
                                                        <span class="form-check-label">
                                                            {{ translate('messages.Use Business Logo') }}
                                                        </span>
                                                    </label>
                                                    <label class="form-check form--check mr-2 mr-md-4">
                                                        <input class="form-check-input" type="radio" value="" name="display_logo" id="upload_new_logo">
                                                        <span class="form-check-label">
                                                            {{ translate('messages.Upload New') }}
                                                        </span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="upload_new_logo_wrapper text-center">
                                                <div class="mb-4">
                                                    <label for="logo-input">
                                                        <h5 class="mb-1">
                                                            {{ translate('Logo') }}
                                                        </h5>
                                                        <span class="text-danger">*</span>
                                                    </label>
                                                    <p class="mb-0 fs-12 gray-dark">{{ translate('Upload your Business Logo') }}</p>
                                                </div>
                                                <div class="text-center">
                                                    @include('admin-views.partials._image-uploader', [
                                                    'id' => 'upload-new-logo-input',
                                                    'name' => 'logo',
                                                    'ratio' => '3:1',
                                                    'isRequired' => true ,
                                                    'existingImage' => null,
                                                    'imageExtension' => IMAGE_EXTENSION,
                                                    'imageFormat' => IMAGE_FORMAT,
                                                    'maxSize' => MAX_FILE_SIZE,
                                                ])
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card card-body mb-20">
                                <div class="view-details-container">
                                    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap flex-sm-nowrap">
                                        <div>
                                            <h4 class="mb-1">{{ translate('messages.Business Identity') }}</h4>
                                            <p class="mb-0 fs-12">
                                                {{ translate('messages.Select your business identity type from the provided options') }}
                                            </p>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <div
                                                class="view-btn active order-sm-0 order-3 fz--14px text-primary cursor-pointer text-decoration-underline font-semibold d-flex align-items-center gap-1">
                                                {{ translate('messages.view') }}
                                                <i class="tio-chevron-down"></i>
                                            </div>
                                            <label class="toggle-switch toggle-switch-sm m-0">
                                                <input type="checkbox" class="toggle-switch-input" name="" id="identity-switch" data-target="#tax_id_text,#bin_number_text,#musak_text" value="">
                                                <span class="toggle-switch-label">
                                                    <div class="toggle-switch-indicator"></div>
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="view-details mt-3 d-block">
                                        <div class="__bg-F8F9FC-card">
                                            <div class="mb-20">
                                                <label class="input-label text-capitalize d-inline-flex align-items-center">
                                                    {{ translate('messages.Choose Business Identity') }}
                                                </label>
                                                <div class="resturant-type-group border bg-white">
                                                    <label class="form-check form--check mr-2 mr-md-4">
                                                        <input class="form-check-input" type="radio" value="" name="business_identity" id="tex_id" checked="">
                                                        <span class="form-check-label">
                                                            {{ translate('messages.Tax Id') }}
                                                        </span>
                                                    </label>
                                                    <label class="form-check form--check mr-2 mr-md-4">
                                                        <input class="form-check-input" type="radio" value="" name="business_identity" id="bin_number">
                                                        <span class="form-check-label">
                                                            {{ translate('messages.Bin Number') }}
                                                        </span>
                                                    </label>
                                                    <label class="form-check form--check mr-2 mr-md-4">
                                                        <input class="form-check-input" type="radio" value="" name="business_identity" id="musak">
                                                        <span class="form-check-label">
                                                            {{ translate('messages.Musak') }}
                                                        </span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="tax_input_wrapper">
                                                <label class="input-label text-capitalize d-inline-flex align-items-center gap-1">
                                                    {{ translate('messages.Tax Number') }} <span class="text-danger">*</span>
                                                </label>
                                                <input type="number" class="form-control" id="tax_number_input" placeholder="{{ translate('messages.Type your Tax Number') }}">
                                            </div>
                                            <div class="bin_input_wrapper d--none">
                                                <label class="input-label text-capitalize d-inline-flex align-items-center gap-1">
                                                    {{ translate('messages.Bin Number') }} <span class="text-danger">*</span>
                                                </label>
                                                <input type="number" class="form-control" id="bin_number_input" placeholder="{{ translate('messages.Type your Bin Number') }}">
                                            </div>
                                            <div class="musak_input_wrapper d--none">
                                                <label class="input-label text-capitalize d-inline-flex align-items-center gap-1">
                                                    {{ translate('messages.Musak') }} <span class="text-danger">*</span>
                                                </label>
                                                <input type="number" class="form-control" id="musak_input" placeholder="{{ translate('messages.Type your Musak') }}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card card-body mb-20">
                                <div class="view-details-container">
                                    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap flex-sm-nowrap">
                                        <div>
                                            <h4 class="mb-1">{{ translate('messages.Terms & Condition on invoice') }}</h4>
                                            <p class="mb-0 fs-12">
                                                {{ translate('messages.Enable the option to update the Terms & Condition that appears on invoices') }}
                                            </p>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <div
                                                class="view-btn active order-sm-0 order-3 fz--14px text-primary cursor-pointer text-decoration-underline font-semibold d-flex align-items-center gap-1">
                                                {{ translate('messages.view') }}
                                                <i class="tio-chevron-down"></i>
                                            </div>
                                            <label class="toggle-switch toggle-switch-sm m-0">
                                                <input type="checkbox" class="toggle-switch-input" name="" id="condition-switch" data-target=".terms-condition-text-wrapper" value="">
                                                <span class="toggle-switch-label">
                                                    <div class="toggle-switch-indicator"></div>
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="view-details mt-3 d-block">
                                        <div class="__bg-F8F9FC-card">
                                            <label for="" class="input-label text-capitalize d-inline-flex align-items-center">
                                                {{ translate('messages.Write Terms & Condition') }}
                                            </label>
                                            <textarea name="" id="terms_condition" rows="2" class="form-control" maxlength="100" placeholder="{{ translate('messages.Type here') }}"></textarea>
                                            <div class="d-flex justify-content-end mt-1">
                                                <span class="text-body-light fs-12">0/100</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card card-body">
                                <div class="view-details-container">
                                    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap flex-sm-nowrap">
                                        <div>
                                            <h4 class="mb-1">{{ translate('messages.Show Copyright Text on invoice') }}</h4>
                                            <p class="mb-0 fs-12">
                                                {{ translate('messages.Enable the option to update the Copyright Text that appears on invoices') }}
                                            </p>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <div
                                                class="view-btn active order-sm-0 order-3 fz--14px text-primary cursor-pointer text-decoration-underline font-semibold d-flex align-items-center gap-1">
                                                {{ translate('messages.view') }}
                                                <i class="tio-chevron-down"></i>
                                            </div>
                                            <label class="toggle-switch toggle-switch-sm m-0">
                                                <input type="checkbox" class="toggle-switch-input" name="" id="copyright-switch" value="" data-target="#copyright-text">
                                                <span class="toggle-switch-label">
                                                    <div class="toggle-switch-indicator"></div>
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="view-details mt-3 d-block">
                                        <div class="__bg-F8F9FC-card">
                                            <label for="" class="input-label text-capitalize d-inline-flex align-items-center">
                                                {{ translate('messages.Write Copyright Text') }}
                                            </label>
                                            <textarea name="" id="copyright" rows="2" class="form-control" maxlength="100" placeholder="{{ translate('messages.Type here') }}"></textarea>
                                            <div class="d-flex justify-content-end mt-1">
                                                <span class="text-body-light fs-12">0/100</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card max-w-400px">
                        <div class="card-header d-block">
                            <h3 class="mb-1">{{ translate('messages.Invoice Preview') }}</h3>
                            <p class="fs-12 mb-0">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Aliquam odio</p>
                        </div>
                        <div class="card-body">
                            <div class="bg-light rounded-10 p-3 p-xxl-20">
                                <div class="bg-white rounded p-3 fs-10">
                                    <div class="text-center mb-3">
                                        <img 
                                            id="preview-logo"
                                            src="{{dynamicAsset('assets/admin/img/100x100/1.png')}}"
                                            data-default="{{dynamicAsset('assets/admin/img/100x100/1.png')}}"
                                            class="w-40px aspect-1 object-cover rounded mb-1"
                                            >
                                        <div class="text--black mb-1">Hungry Puppets</div>
                                        <div class="text--black">{{ translate('messages.House') }}: 00, Road: 00, Test City</div>
                                        <div id="tax_id_text" class="text-muted mt-1">{{ translate('messages.Tax Id') }} : 8494646894</div>
                                        <div id="bin_number_text" class="text-muted mt-1 d--none">{{ translate('messages.Bin Number') }} : 8494646894</div>
                                        <div id="musak_text" class="text-muted mt-1 d--none">{{ translate('messages.Musak') }} : 8494646894</div>
                                    </div>
                                    <div>
                                        <div class="d-flex justify-content-between text--black mb-2">
                                            <span>{{ translate('messages.Order Type') }}</span>
                                            <span>{{ translate('messages.Home Delivery') }}</span>
                                        </div>
                                        <div class="border-dashed-gray rounded px-2">
                                            <table class="table table-borderless table-sm fs-10 mb-0 overflow-wrap-anywhere">
                                                <tbody>
                                                    <tr>
                                                        <td class="text-nowrap">{{ translate('messages.Order ID') }}</td>
                                                        <td class="text-right text--black">100157</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-nowrap">{{ translate('messages.Customer Name') }}</td>
                                                        <td class="text-right text--black">Victor Shoaga</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-nowrap">{{ translate('messages.Phone') }}</td>
                                                        <td class="text-right text--black">+8**********</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-nowrap">{{ translate('messages.Delivery Address') }}</td>
                                                        <td class="text-right text--black">
                                                            7953 Oakland St. Honolulu, HI 96815
                                                            <br>
                                                            Street no: 02, House: 23a, floor: 4
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="table-responsive pb-0">
                                        <table class="table table-borderless table-sm fs-10 mb-0 overflow-wrap-anywhere">
                                            <tbody>
                                                <tr>
                                                    <td class="text--black">{{ translate('messages.QTY') }}</td>
                                                    <td class="text--black">{{ translate('messages.Item') }}</td>
                                                    <td class="text--black text-right">{{ translate('messages.Price') }}</td>
                                                </tr>
                                                <tr>
                                                    <td colspan="3">
                                                        <div class="border-top-dashed-gray"></div>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="text--black">2x</td>
                                                    <td class="text--black">
                                                        <div>grilled lemon herb Mediterranean chicken salad</div>
                                                        <div>Variation :</div>
                                                        <div class="text-muted">Small : $ 220.00</div>
                                                        <div class="text-muted">Medium : $ 320.00</div>
                                                    </td>
                                                    <td class="text--black text-right text-nowrap">$1,720.00</td>
                                                </tr>
                                                <tr>
                                                    <td colspan="3">
                                                        <div class="border-top-dashed-gray"></div>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="text-nowrap" colspan="2">{{ translate('messages.Item Price') }}</td>
                                                    <td class="text-right text--black text-nowrap">$ 1,720.00</td>
                                                </tr>
                                                <tr>
                                                    <td colspan="2" class="text-nowrap">{{ translate('messages.Addon Cost') }}</td>
                                                    <td class="text-right text--black">$ 0.00</td>
                                                </tr>
                                                <tr>
                                                    <td colspan="3">
                                                        <div class="border-top-dashed-gray"></div>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="text-nowrap text--black fs-12 font-medium">{{ translate('messages.Subtotal') }}</td>
                                                    <td colspan="2" class="text-right text--black fs-12 font-medium text-nowrap">$ 0.00</td>
                                                </tr>
                                                <tr>
                                                    <td colspan="3">
                                                        <div class="border-top-dashed-gray"></div>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td colspan="2" class="text-nowrap">{{ translate('messages.Discount') }}</td>
                                                    <td class="text-right text--black text-nowrap">- $ 0.00</td>
                                                </tr>
                                                <tr>
                                                    <td colspan="2" class="text-nowrap">{{ translate('messages.Coupon discount') }}</td>
                                                    <td class="text-right text--black text-nowrap">- $ 0.00</td>
                                                </tr>
                                                <tr>
                                                    <td colspan="2" class="text-nowrap">{{ translate('messages.Vat/tax') }}</td>
                                                    <td class="text-right text--black text-nowrap">$ 86.00</td>
                                                </tr>
                                                <tr>
                                                    <td colspan="2" class="text-nowrap">{{ translate('messages.Delivery man tips') }}</td>
                                                    <td class="text-right text--black text-nowrap">- $ 0.00</td>
                                                </tr>
                                                <tr>
                                                    <td colspan="2" class="text-nowrap">{{ translate('messages.Delivery charge') }}</td>
                                                    <td class="text-right text--black text-nowrap">$ 583.99</td>
                                                </tr>
                                                <tr>
                                                    <td colspan="2" class="text-nowrap">{{ translate('messages.Service Charge') }}</td>
                                                    <td class="text-right text--black text-nowrap">+ $ 10.00</td>
                                                </tr>
                                                <tr>
                                                    <td colspan="3">
                                                        <div class="border-top-dashed-gray"></div>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="text-nowrap text--black fs-16 font-bold">{{ translate('messages.Total') }}</td>
                                                    <td colspan="2" class="text-right text--black fs-16 font-bold text-nowrap">$ 2,399.99</td>
                                                </tr>
                                                <tr>
                                                    <td colspan="3">
                                                        <div class="border-top-dashed-gray"></div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="text--black fs-12 mb-2 text-center terms-condition-text-wrapper">
                                        {{ translate('messages.Terms & Condition') }} : <span id="terms-condition-text">Lorem ipsum dolor sit amet, consectetur adipiscing elit,</span>
                                    </div>
                                    <div class="text--black fs-12 mb-3 text-center">
                                        <h4 class="text--black fs-16 font-bold mb-0">{{ translate('messages.Thank You') }}</h4>
                                        <div>{{ translate('messages.for ordering food from Stackfood') }}</div>
                                    </div>
                                    <div class="text--black fs-10 pt-3 border-top-dashed-gray text-center" id="copyright-text">
                                        © {{ translate('messages.2024 StackFood. All right reserved') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="footer-sticky mt-2">
                <div class="container-fluid">
                <div class="d-flex flex-wrap gap-3 justify-content-center py-3">
                    <button type="reset" id="reset_btn" class="btn btn--secondary min-w-120 location-reload">{{ translate('messages.Reset') }} </button>
                    <button type="{{ env('APP_MODE') != 'demo' ? 'submit' : 'button' }}" class="btn btn--primary call-demo">
                        <i class="tio-save"></i>
                        {{ translate('Save_Information') }}
                    </button>
                </div>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('script_2')
    <script>
        $(document).ready(function () {

            const defaultLogo = $('#preview-logo').data('default');
            let uploadedLogo = null;

            function applySwitchState(el){
                const target = $(el).data('target');
                if(!target) return;

                if($(el).is(':checked')){
                    $(target).removeClass('d--none').show();
                }else{
                    $(target).addClass('d--none').hide();
                }
            }

            $('.toggle-switch-input').on('change', function(){
                applySwitchState(this);
                updateIdentityPreview();
            });

            function updateLogoOption(){
                if($('#upload_new_logo').is(':checked')){
                    $('.upload_new_logo_wrapper').slideDown(0);
                    if(uploadedLogo){
                        $('#preview-logo').attr('src', uploadedLogo);
                    }
                }else{
                    $('.upload_new_logo_wrapper').slideUp(0);
                    $('#preview-logo').attr('src', defaultLogo);
                }
            }

            $('input[name="display_logo"]').on('change', updateLogoOption);

            $(document).on('change','input[type="file"]',function(){

                if(!$('#upload_new_logo').is(':checked')) return;

                const file = this.files[0];
                if(!file) return;

                const reader = new FileReader();

                reader.onload = function(e){
                    uploadedLogo = e.target.result;
                    $('#preview-logo').attr('src', uploadedLogo);
                }

                reader.readAsDataURL(file);

            });

            function updateIdentityPreview(){

                $('#tax_id_text,#bin_number_text,#musak_text').addClass('d--none');

                if(!$('#identity-switch').is(':checked')) return;

                if($('#tex_id').is(':checked')){
                    $('#tax_id_text').removeClass('d--none');
                }

                if($('#bin_number').is(':checked')){
                    $('#bin_number_text').removeClass('d--none');
                }

                if($('#musak').is(':checked')){
                    $('#musak_text').removeClass('d--none');
                }

            }

            function updateIdentityInput(){

                $('.tax_input_wrapper,.bin_input_wrapper,.musak_input_wrapper').addClass('d--none');

                if($('#tex_id').is(':checked')){
                    $('.tax_input_wrapper').removeClass('d--none');
                }

                if($('#bin_number').is(':checked')){
                    $('.bin_input_wrapper').removeClass('d--none');
                }

                if($('#musak').is(':checked')){
                    $('.musak_input_wrapper').removeClass('d--none');
                }

            }

            $('input[name="business_identity"]').on('change', function(){
                updateIdentityPreview();
                updateIdentityInput();
            });

            $('#terms_condition').on('input', function () {
                $('#terms-condition-text').text($(this).val());
            });

            $('#copyright').on('input', function () {
                $('#copyright-text').text($(this).val());
            });

            $('#tax_number_input').on('input', function () {
                $('#tax_id_text').text('Tax Id : ' + $(this).val());
            });

            $('#bin_number_input').on('input', function () {
                $('#bin_number_text').text('BIN : ' + $(this).val());
            });

            $('#musak_input').on('input', function () {
                $('#musak_text').text('Musak : ' + $(this).val());
            });

            function initState(){

                $('.toggle-switch-input').each(function(){
                    applySwitchState(this);
                });

                updateLogoOption();
                updateIdentityPreview();
                updateIdentityInput();

            }

            initState();

        });
    </script>
@endpush
