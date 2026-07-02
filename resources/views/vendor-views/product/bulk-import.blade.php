@extends('layouts.vendor.app')

@section('title',translate('messages.foods_bulk_import'))

@push('css_or_js')
    <style>
        .nz-bulk-import-page .page-header { margin-bottom: 14px; }
        .nz-bulk-import-page .page-header-title { font-size: 22px; font-weight: 900; color: #102a4c; letter-spacing: 0; }
        .nz-import-card { border: 1px solid #e6eaf0; border-radius: 10px; box-shadow: 0 1px 4px rgba(16,24,40,.04); overflow: hidden; }
        .nz-import-card .card-body { padding: 20px; }
        .nz-import-steps { display: grid !important; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-bottom: 18px; }
        .nz-import-steps .export-steps-item { width: 100% !important; margin: 0 !important; border: 1px solid #edf1f5; border-radius: 8px; background: #f8fafc; }
        .nz-import-steps .inner { width: 100%; min-height: 70px; padding: 13px 14px !important; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }
        .nz-import-steps h5 { margin-bottom: 4px; color: #102a4c; font-size: 14px; font-weight: 900; }
        .nz-import-steps p { margin: 0; color: #667085; font-size: 12.5px; line-height: 1.35; font-weight: 700; }
        .nz-import-guide-grid { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(300px, .8fr); gap: 18px; align-items: stretch; }
        .nz-import-instructions { padding: 18px 20px; border: 1px solid #edf1f5; border-radius: 10px; background: #fff; }
        .nz-import-instructions h2 { margin-bottom: 14px; font-size: 18px; line-height: 1.25; font-weight: 900; color: #1262a8; }
        .nz-import-instructions p { margin-bottom: 10px; color: #667085; font-size: 13px; line-height: 1.55; font-weight: 700; }
        .nz-import-template-panel { display: flex; flex-direction: column; justify-content: center; padding: 18px; border: 1px solid #edf1f5; border-radius: 10px; background: #f8fafc; }
        .nz-import-template-panel h3 { margin-bottom: 14px; color: #102a4c; font-size: 17px; font-weight: 900; text-align: center; }
        .nz-import-template-panel .btn--container { gap: 10px; }
        .nz-import-template-panel .btn { min-height: 42px; border-radius: 7px; font-weight: 800; }
        .nz-import-upload-card .card-body { padding: 18px 20px; }
        .nz-import-upload-row { display: grid; grid-template-columns: minmax(260px, 420px) minmax(260px, 1fr); align-items: end; gap: 16px; }
        .nz-import-upload-row h4 { margin: 0 0 10px; color: #102a4c; font-size: 16px; font-weight: 900; }
        .nz-import-upload-actions { display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
        .nz-import-upload-actions .btn { min-width: 118px; min-height: 42px; border-radius: 7px; font-weight: 900; }
        .nz-variation-card .card-header { padding: 15px 18px; background: #fff; border-bottom: 1px solid #edf1f5; }
        .nz-variation-card .card-body { padding: 18px; }
        .nz-variation-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
        .nz-variation-toolbar .btn { border-radius: 7px; font-weight: 800; }
        .nz-variation-card textarea { min-height: 104px; border-radius: 8px; background: #fbfcfe; }
        .nz-file-clean {
            position: relative;
            height: 42px;
        }
        .nz-file-clean input[type="file"] {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
            z-index: 2;
        }
        .nz-file-clean .custom-file-label {
            display: flex;
            align-items: center;
            height: 42px;
            padding: 0 14px;
            border: 1px solid #d8e0ea;
            border-radius: 7px;
            color: transparent;
            background: #fff;
            overflow: hidden;
        }
        .nz-file-clean .custom-file-label::before {
            content: attr(data-label);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 94px;
            height: 30px;
            padding: 0 14px;
            border-radius: 6px;
            background: #f4f6f9;
            color: #344054;
            font-size: 13px;
            font-weight: 700;
        }
        .nz-file-clean .custom-file-label::after {
            display: none;
        }
        @media (max-width: 991.98px) {
            .nz-import-guide-grid,
            .nz-import-upload-row { grid-template-columns: 1fr; }
            .nz-import-upload-actions { justify-content: flex-start; }
        }
        @media (max-width: 767.98px) {
            .nz-import-steps { grid-template-columns: 1fr; }
            .nz-import-card .card-body { padding: 14px; }
            .nz-import-instructions, .nz-import-template-panel { padding: 14px; }
            .nz-import-upload-actions .btn { flex: 1 1 120px; }
        }
    </style>
@endpush

@section('content')
    <div class="content container-fluid nz-bulk-import-page">
        <div class="page-header">
            <h1 class="page-header-title mb-2 text-capitalize">
                <div class="card-header-icon d-inline-flex mr-2 img">
                    <img src="{{dynamicAsset('assets/admin/img/export.png')}}" alt="">
                </div>
                {{translate('messages.foods_bulk_import')}}
            </h1>
        </div>
        <div class="card mb-3 nz-import-card">
            <div class="card-body">
                <div class="export-steps style-2 nz-import-steps">
                    <div class="export-steps-item">
                        <div class="inner">
                            <h5>{{ translate('STEP_1') }}</h5>
                            <p>
                                {{ translate('Download_Excel_File') }}
                            </p>
                        </div>
                    </div>
                    <div class="export-steps-item">
                        <div class="inner">
                            <h5>{{ translate('STEP_2') }}</h5>
                            <p>
                                {{ translate('Match_Spread_sheet_data_according_to_instruction') }}
                            </p>
                        </div>
                    </div>
                    <div class="export-steps-item">
                        <div class="inner">
                            <h5>{{ translate('STEP_3') }}</h5>
                            <p>
                                {{ translate('Validate_data_and_and_complete_import') }}
                            </p>
                        </div>
                    </div>
                </div>
                <div class="nz-import-guide-grid">
                    <div class="nz-import-instructions">
                        <h2>{{ translate('Instructions') }}</h2>
                        <p>{{ translate('1._Download_the_format_file_and_fill_it_with_proper_data.') }}</p>

                        <p>{{ translate('2._You_can_download_the_example_file_to_understand_how_the_data_must_be_filled.') }}</p>

                        <p>{{ translate('3._Once_you_have_downloaded_and_filled_the_format_file,_upload_it_in_the_form_below_and_submit.') }}</p>

                        <p> {{ translate('4._After_uploading_foods_you_need_to_edit_them_and_set_image_and_variations.') }}</p>

                        <p> {{ translate('5._You_can_get_category_id_from_their_list,_please_input_the_right_ids.') }}</p>

                        <p> {{ translate('6._Don`t_forget_to_fill_all_the_fields') }} </p>

                        <p>{{ translate('7._For_veg_food_enter_1_and_for_non-veg_enter_0_on_veg_field.') }}</p>
                        <p class="mb-0">{{ translate('8._Image_file_name_must_be_in_30_character.') }}</p>
                    </div>
                    <div class="nz-import-template-panel">
                        <h3>{{ translate('Download Spreadsheet Template') }}</h3>
                        <div class="btn--container justify-content-center export--template-btns">
                            <a href="{{dynamicAsset('assets/restaurant_panel/foods_bulk_format.xlsx')}}" download="" class="btn btn-dark">{{ translate('Template_with_Existing_Data') }}</a>
                            <a href="{{dynamicAsset('assets/restaurant_panel/foods_bulk_format_nodata.xlsx')}}" download="" class="btn btn-dark">{{ translate('Template_without_Data') }}</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <form class="product-form" id="import_form" action="{{route('vendor.food.bulk-import')}}" method="POST"
                enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="button" id="btn_value">
            <div class="card rest-part nz-import-card nz-import-upload-card mb-3">
                <div class="card-body">
                    <div class="nz-import-upload-row">
                        <div>
                            <h4>{{ translate('Import Foods') }}</h4>
                            <div class="custom-file custom--file nz-file-clean">
                                <input type="file" name="products_file" class="custom-file-input" id="bulk__import">
                                <label class="custom-file-label" for="bulk__import" data-label="{{ translate('Choose File') }}">{{ translate('Choose File') }}</label>
                            </div>
                        </div>
                        <div class="nz-import-upload-actions">
                        <button id="reset_btn" type="reset" class="btn btn--reset">{{translate('messages.reset')}}</button>
                        <button type="submit" name="button" value="update" class="btn btn--warning submit_btn">{{translate('messages.update')}}</button>
                        <button type="submit" name="button" value="import" class="btn btn--primary submit_btn">{{translate('messages.Import')}}</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>



        <form action="javascript:" method="post" id="item_form" enctype="multipart/form-data">
            @csrf
            <div id="food_variation_section" >
                <div class="card rest-part nz-import-card nz-variation-card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <span class="card-header-icon">
                            </span>
                            <span>{{ translate('messages.food_variations_generator') }}</span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-md-12">
                                <div id="add_new_option">
                                </div>
                                <div class="nz-variation-toolbar">
                                    <a class="btn btn-outline-success"
                                        id="add_new_option_button">{{ translate('add_new_variation') }}</a>
                                    <button type="submit" class="btn btn--primary">{{translate('generate')}}</button>
                                </div>
                            </div>
                        </div>
                        <textarea name="" id="food_variation_outpot" class="form-control" rows="5" readonly></textarea>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('script_2')
<script>
    "use strict";
    let count = 0;
    let countRow = 0;
    let element = 0;
    $(document).ready(function() {
        $("#add_new_option_button").click(function(e) {
            count++;
            let add_option_view = `
                <div class="card view_new_option mb-2" >
                    <div class="card-header">
                        <label for="" id=new_option_name_` + count + `> {{ translate('add_new') }}</label>
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-lg-3 col-md-6">
                                <label for="">{{ translate('name') }}</label>
                                 <input required name=options[` + count +
                `][name] class="form-control new_option_name" type="text" data-count="`+
                count +`">
                            </div>

                            <div class="col-lg-3 col-md-6">
                                <div class="form-group">
                                    <label class="input-label text-capitalize d-flex alig-items-center"><span class="line--limit-1">{{ translate('messages.selcetion_type') }} </span>
                                    </label>
                                    <div class="resturant-type-group border">
                                        <label class="form-check form--check mr-2 mr-md-4">
                                                <input class="form-check-input show_min_max" data-count="`+count+`" type="radio" value="multi"
                                                name="options[` + count + `][type]" id="type` + count +
                `" checked
                                                >
                                                <span class="form-check-label">
                                                    {{ translate('Multiple Selection') }}
                </span>
            </label>

            <label class="form-check form--check mr-2 mr-md-4">
                <input class="form-check-input hide_min_max" data-count="`+count+`" type="radio" value="single"
                                                name="options[` + count + `][type]" id="type` + count +
                `"
                                                >
                                                <span class="form-check-label">
                                                    {{ translate('Single Selection') }}
                </span>
            </label>
    </div>
</div>
</div>
<div class="col-12 col-lg-6">
<div class="row g-2">
    <div class="col-sm-6 col-md-4">
        <label for="">{{ translate('Min') }}</label>
                                        <input id="min_max1_` + count + `" required  name="options[` + count + `][min]" class="form-control" type="number" min="1">
                                    </div>
                                    <div class="col-sm-6 col-md-4">
                                        <label for="">{{ translate('Max') }}</label>
                                        <input id="min_max2_` + count + `"   required name="options[` + count + `][max]" class="form-control" type="number" min="2">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="d-md-block d-none">&nbsp;</label>
                                            <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <input id="options[` + count + `][required]" name="options[` +
                count + `][required]" type="checkbox">
                                                <label for="options[` + count + `][required]" class="m-0">{{ translate('Required') }}</label>
                                            </div>
                                            <div>
                                                <button type="button" class="btn btn-danger btn-sm delete_input_button"
                                                    title="{{ translate('Delete') }}">
                                                    <i class="tio-add-to-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="option_price_` + count + `" >
                            <div class="border rounded p-3 pb-0 mt-3">
                                <div  id="option_price_view_` + count + `">
                                    <div class="row g-3 add_new_view_row_class mb-3">
                                        <div class="col-md-4 col-sm-6">
                                            <label for="">{{ translate('Option_name') }}</label>
                                            <input class="form-control" required type="text" name="options[` +
                count +
                `][values][0][label]" id="">
                                        </div>
                                        <div class="col-md-4 col-sm-6">
                                            <label for="">{{ translate('Additional_price') }}</label>
                                            <input class="form-control" required type="number" min="0" step="0.01" name="options[` +
                count + `][values][0][optionPrice]" id="">
                                        </div>
                                    </div>
                                </div>
                                <div class="row mt-3 p-3 mr-1 d-flex "  id="add_new_button_` + count +
                `">
                                   <button type="button" class="btn btn--primary btn-outline-primary add_new_row_button" data-count="`+
                count +`" >{{ translate('Add_New_Option') }}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>`;

            $("#add_new_option").append(add_option_view);
        });
    });

    function show_min_max(data) {
        $('#min_max1_' + data).removeAttr("readonly");
        $('#min_max2_' + data).removeAttr("readonly");
        $('#min_max1_' + data).attr("required", "true");
        $('#min_max2_' + data).attr("required", "true");
    }

    function hide_min_max(data) {
        $('#min_max1_' + data).val(null).trigger('change');
        $('#min_max2_' + data).val(null).trigger('change');
        $('#min_max1_' + data).attr("readonly", "true");
        $('#min_max2_' + data).attr("readonly", "true");
        $('#min_max1_' + data).attr("required", "false");
        $('#min_max2_' + data).attr("required", "false");
    }

    $(document).on('change', '.show_min_max', function () {
        let data = $(this).data('count');
        show_min_max(data);
    });

    $(document).on('change', '.hide_min_max', function () {
        let data = $(this).data('count');
        hide_min_max(data);
    });


    function new_option_name(value, data) {
        $("#new_option_name_" + data).empty();
        $("#new_option_name_" + data).text(value)
        console.log(value);
    }

    function removeOption(e) {
        element = $(e);
        element.parents('.view_new_option').remove();
    }

    function deleteRow(e) {
        element = $(e);
        element.parents('.add_new_view_row_class').remove();
    }


    $(document).on('click', '.delete_input_button', function () {
        let e = $(this);
        removeOption(e);
    });
    $(document).on('click', '.deleteRow', function () {
        let e = $(this);
        deleteRow(e);
    });
    function add_new_row_button(data) {
        count = data;
        countRow = 1 + $('#option_price_view_' + data).children('.add_new_view_row_class').length;
        let add_new_row_view = `
        <div class="row add_new_view_row_class mb-3 position-relative pt-3 pt-sm-0">
            <div class="col-md-4 col-sm-5">
                    <label for="">{{ translate('Option_name') }}</label>
                    <input class="form-control" required type="text" name="options[` + count + `][values][` +
            countRow + `][label]" id="">
                </div>
                <div class="col-md-4 col-sm-5">
                    <label for="">{{ translate('Additional_price') }}</label>
                    <input class="form-control"  required type="number" min="0" step="0.01" name="options[` +
            count +
            `][values][` + countRow + `][optionPrice]" id="">
                </div>
                <div class="col-sm-2 max-sm-absolute">
                    <label class="d-none d-sm-block">&nbsp;</label>
                    <div class="mt-1">
                        <button type="button" class="btn btn-danger btn-sm deleteRow"
                            title="{{ translate('Delete') }}">
                            <i class="tio-add-to-trash"></i>
                        </button>
                    </div>
            </div>
        </div>`;
        $('#option_price_view_' + data).append(add_new_row_view);

    }

    $(document).on('click', '.add_new_row_button', function () {
        let data = $(this).data('count');
        add_new_row_button(data);
    });

    $(document).on('keyup', '.new_option_name', function () {
        let data = $(this).data('count');
        let value = $(this).val();
        new_option_name(value, data);
    });

    $('#item_form').on('submit', function(e) {
        e.preventDefault();
        let formData = new FormData(this);
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });
        $.post({
            url: '{{ route('vendor.food.food-variation-generate') }}',
            data: $('#item_form').serialize(),
            data: formData,
            cache: false,
            contentType: false,
            processData: false,
            beforeSend: function() {
                $('#loading').show();
            },
            success: function(data) {
                $('#loading').hide();
                if (data.errors) {
                    for (let i = 0; i < data.errors.length; i++) {
                        toastr.error(data.errors[i].message, {
                            CloseButton: true,
                            ProgressBar: true
                        });
                    }
                } else {
                    $('#food_variation_outpot').val(data.variation)
                }
            }
        });
    });


    $(document).on("click", ".submit_btn", function(e){
        e.preventDefault();
            let data = $(this).val();
            myFunction(data)
    });


    function myFunction(data) {
        Swal.fire({
        title: '{{ translate('Are_you_sure?') }}' ,
        text: "{{ translate('You_want_to_') }}" +data,
        type: 'warning',
        showCancelButton: true,
        cancelButtonColor: 'default',
        confirmButtonColor: '#FC6A57',
        cancelButtonText: '{{ translate('no') }}',
        confirmButtonText: '{{ translate('yes') }}',
        reverseButtons: true
        }).then((result) => {
            if (result.value) {
                $('#btn_value').val(data);
                $("#import_form").submit();
            }
        })
    }

    document.getElementById('import_form').addEventListener('change', function(e) {
    const fileInput = document.getElementById('bulk__import');
    const file = fileInput.files[0];

        if (!file) {
            toastr.error('{{ translate("Please select a file to upload") }}');
            e.preventDefault();
            return;
        }

        const allowedExtensions = ['xlsx', 'csv'];
        const fileExtension = file.name.split('.').pop().toLowerCase();

        if (!allowedExtensions.includes(fileExtension)) {
            toastr.error('{{ translate("Invalid file type. Only CSV and Excel files are allowed.") }}');
            e.preventDefault(); 
            fileInput.value = ''; 
            return;
        }
    });
    $(document).on('change input', 'input[id^="min_max"]', function() {
        let count = $(this).attr('id').split('_').pop();
        let minInput = $('#min_max1_' + count);
        let maxInput = $('#min_max2_' + count);

        let minVal = parseInt(minInput.val()) || 0;
        let maxVal = parseInt(maxInput.val()) || 0;

        if ($(this).attr('id') === 'min_max1_' + count) {
            if (minVal < 1 && minInput.val() !== "") {
                minVal = 1;
            }
        }

        if ($(this).attr('id') === 'min_max2_' + count) {
            if (maxVal < 2 && maxInput.val() !== "") {
                maxVal = 2;
            }
        }
    });
</script>
@endpush
