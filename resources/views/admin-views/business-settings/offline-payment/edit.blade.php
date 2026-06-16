@extends('layouts.admin.app')
@section('title', translate('Edit_Offline_Payment_Method'))

@section('content')
    {{-- 哪吒 B方案: 见 new.blade 说明。编辑同样写 canonical schema, 不会反噬结算。 --}}
    <div class="content container-fluid">
        <div class="mb-4 pb-2">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <img src="{{dynamicAsset('assets/admin/img/3rd-party.png')}}" alt="">
                {{ translate('Offline_Payment_Method_Edit') }}
            </h2>
        </div>

        @php
            $rawNote = $data->getRawOriginal('method_informations');
            $noteVal = (!$rawNote || in_array(trim($rawNote), ['[]','{}','null','""'])) ? '' : $rawNote;
            $fields = is_array($data->method_fields) ? $data->method_fields : [];
        @endphp

        <form action="{{ route('admin.business-settings.offline.update') }}" method="POST">
            @csrf
            <input type="hidden" name="id" value="{{ $data->id }}">
            <div class="card mt-3">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <img width="25" src="{{dynamicAsset('assets/admin/img/payment-card.png')}}" alt="">
                        <h4 class="page-title mt-2 mb-0">{{ translate('payment_Method_Name') }}</h4>
                    </div>
                    <button type="button" class="btn btn--primary" id="add-field"><i class="tio-add"></i> {{ translate('Add_New_Field') }}</button>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-xl-5 col-sm-8">
                            <div class="form-group">
                                <label for="method_name" class="title_color">{{ translate('payment_Method_Name') }} *</label>
                                <input type="text" class="form-control text-break" id="method_name" name="method_name" required value="{{ $data->method_name }}">
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h5 class="mb-1">{{ translate('需顾客提供的信息（结算时填写）') }}</h5>
                    <p class="text-muted fs-12">{{ translate('每一行 = 顾客在结算页要填的一个字段。「文件截图」用于付款截图，「文本」用于交易哈希等。') }}</p>
                    <div class="d-flex flex-column gap-2" id="fields-section">
                        @foreach ($fields as $i => $item)
                            @php
                                $label = $item['input_field_name'] ?? ($item['input_name'] ?? '');
                                $ph    = $item['placeholder'] ?? '';
                                $type  = ($item['input_type'] ?? 'text') === 'file' ? 'file' : 'text';
                                $req   = (isset($item['is_required']) && (int)$item['is_required']) ? 1 : 0;
                            @endphp
                            <div class="row align-items-end field-row mb-1">
                                <div class="col-md-4">
                                    <div class="form-group mb-2">
                                        <label class="title_color">{{ translate('字段名') }} *</label>
                                        <input type="text" class="form-control" name="field_label[{{ $i }}]" required value="{{ $label }}">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group mb-2">
                                        <label class="title_color">{{ translate('占位提示') }}</label>
                                        <input type="text" class="form-control" name="field_placeholder[{{ $i }}]" value="{{ $ph }}">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group mb-2">
                                        <label class="title_color">{{ translate('类型') }} *</label>
                                        <select class="form-control" name="field_type[{{ $i }}]">
                                            <option value="text" {{ $type==='text'?'selected':'' }}>{{ translate('文本') }}</option>
                                            <option value="file" {{ $type==='file'?'selected':'' }}>{{ translate('文件截图') }}</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group mb-2">
                                        <div class="d-flex justify-content-between align-items-center gap-2">
                                            <div class="form-check">
                                                <input type="hidden" name="field_required[{{ $i }}]" value="0">
                                                <input class="form-check-input" type="checkbox" value="1" name="field_required[{{ $i }}]" id="req{{ $i }}" {{ $req?'checked':'' }}>
                                                <label class="form-check-label" for="req{{ $i }}">{{ translate('必填') }}</label>
                                            </div>
                                            <span class="btn action-btn btn--danger btn-outline-danger remove-field" style="cursor:pointer;"><i class="tio-delete-outlined"></i></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <div class="form-group mb-0">
                        <label for="payment_note" class="title_color">{{ translate('支付说明（选填，给运营留底）') }}</label>
                        <textarea class="form-control" name="payment_note" id="payment_note" rows="3">{{ $noteVal }}</textarea>
                    </div>
                </div>
            </div>

            <div class="btn--container justify-content-end mt-3">
                <button type="submit" class="btn btn--primary">{{ translate('Submit') }}</button>
            </div>
        </form>
    </div>

    <template id="field-row-tpl">
        <div class="row align-items-end field-row mb-1">
            <div class="col-md-4">
                <div class="form-group mb-2">
                    <label class="title_color">{{ translate('字段名') }} *</label>
                    <input type="text" class="form-control" name="field_label[__I__]" placeholder="{{ translate('例: 交易哈希(Hash) / 付款截图') }}" required>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-group mb-2">
                    <label class="title_color">{{ translate('占位提示') }}</label>
                    <input type="text" class="form-control" name="field_placeholder[__I__]" placeholder="{{ translate('例: 请输入转账交易哈希') }}">
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group mb-2">
                    <label class="title_color">{{ translate('类型') }} *</label>
                    <select class="form-control" name="field_type[__I__]">
                        <option value="text">{{ translate('文本') }}</option>
                        <option value="file">{{ translate('文件截图') }}</option>
                    </select>
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group mb-2">
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <div class="form-check">
                            <input type="hidden" name="field_required[__I__]" value="0">
                            <input class="form-check-input" type="checkbox" value="1" name="field_required[__I__]" id="req__I__" checked>
                            <label class="form-check-label" for="req__I__">{{ translate('必填') }}</label>
                        </div>
                        <span class="btn action-btn btn--danger btn-outline-danger remove-field" style="cursor:pointer;"><i class="tio-delete-outlined"></i></span>
                    </div>
                </div>
            </div>
        </div>
    </template>
@endsection

@push('script_2')
    <script>
        "use strict";
        jQuery(document).ready(function ($) {
            let idx = {{ count($fields) }};
            const tpl = document.getElementById('field-row-tpl').innerHTML;
            $('#add-field').on('click', function () {
                if (idx < 14) { $('#fields-section').append(tpl.replace(/__I__/g, idx)); idx++; }
                else Swal.fire({title:'{{ translate('Reached maximum') }}', confirmButtonText:'{{ translate('ok') }}'});
            });
            $(document).on('click', '.remove-field', function () { $(this).closest('.field-row').remove(); });
        });
    </script>
@endpush
