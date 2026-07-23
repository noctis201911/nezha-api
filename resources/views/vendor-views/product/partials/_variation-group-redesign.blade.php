@php
    $groupType = data_get($item, 'type', 'single');
    $groupRequired = data_get($item, 'required', 'on') === 'on';
    $groupValues = data_get($item, 'values', []);
@endphp

<div
    class="nz-variation-group view_new_option"
    data-variation-index="{{ $groupIndex }}"
    data-variation-id="{{ data_get($item, 'variation_id') }}"
>
    <input
        type="hidden"
        name="options[{{ $groupIndex }}][variation_id]"
        value="{{ data_get($item, 'variation_id') }}"
    >

    <div class="nz-variation-group__top">
        <label class="form-check form--check nz-required-toggle">
            <input
                class="form-check-input nz-required-checkbox"
                name="options[{{ $groupIndex }}][required]"
                type="checkbox"
                value="on"
                @checked($groupRequired)
            >
            <span class="form-check-label">顾客必选</span>
        </label>
        <button
            type="button"
            class="nz-icon-button nz-remove-variation"
            data-id="{{ data_get($item, 'variation_id') }}"
            aria-label="删除规格组"
            title="删除规格组"
        >×</button>
    </div>

    <div class="nz-variation-head-grid">
        <div class="form-group mb-0">
            <label class="input-label">
                规格组名称 <span class="nz-required">*</span>
            </label>
            <input
                type="text"
                name="options[{{ $groupIndex }}][name]"
                class="form-control new_option_name"
                value="{{ data_get($item, 'name') }}"
                placeholder="例如：杯型"
                required
            >
        </div>

        <fieldset class="nz-choice-mode">
            <legend>顾客怎么选</legend>
            <div class="nz-segmented">
                <label>
                    <input
                        type="radio"
                        name="options[{{ $groupIndex }}][type]"
                        value="single"
                        @checked($groupType === 'single')
                    >
                    <span>单选一项</span>
                </label>
                <label>
                    <input
                        type="radio"
                        name="options[{{ $groupIndex }}][type]"
                        value="multi"
                        @checked($groupType === 'multi')
                    >
                    <span>可选多项</span>
                </label>
            </div>
        </fieldset>

        <div class="nz-min-max {{ $groupType === 'single' ? 'd-none' : '' }}">
            <div class="form-group mb-0">
                <label class="input-label">至少选</label>
                <input
                    type="number"
                    name="options[{{ $groupIndex }}][min]"
                    class="form-control nz-min-input"
                    min="0"
                    value="{{ $groupType === 'single' ? 1 : data_get($item, 'min', $groupRequired ? 1 : 0) }}"
                >
            </div>
            <div class="form-group mb-0">
                <label class="input-label">最多选</label>
                <input
                    type="number"
                    name="options[{{ $groupIndex }}][max]"
                    class="form-control nz-max-input"
                    min="1"
                    value="{{ $groupType === 'single' ? 1 : data_get($item, 'max', max(1, count($groupValues))) }}"
                >
            </div>
        </div>
    </div>

    <div class="nz-option-columns" aria-hidden="true">
        <span>选项名称</span>
        <span>加价（{{ \App\CentralLogics\Helpers::currency_symbol() }}）· 0 = 不加价</span>
        <span>限量</span>
        <span></span>
    </div>

    <div class="nz-option-list" id="option_price_view_{{ $groupIndex }}">
        @forelse ($groupValues as $optionIndex => $value)
            <div class="nz-option-row add_new_view_row_class" data-option-index="{{ $optionIndex }}">
                <input
                    type="text"
                    name="options[{{ $groupIndex }}][values][{{ $optionIndex }}][label]"
                    class="form-control"
                    value="{{ data_get($value, 'label') }}"
                    aria-label="选项名称"
                    placeholder="例如：M"
                    required
                >
                <input
                    type="number"
                    name="options[{{ $groupIndex }}][values][{{ $optionIndex }}][optionPrice]"
                    class="form-control"
                    value="{{ data_get($value, 'optionPrice', 0) }}"
                    aria-label="加价"
                    min="0"
                    step="0.01"
                    required
                >
                <input
                    type="number"
                    name="options[{{ $groupIndex }}][values][{{ $optionIndex }}][total_stock]"
                    class="form-control stock_disable nz-option-stock"
                    value="{{ data_get($value, 'total_stock') }}"
                    aria-label="限量"
                    min="0"
                    max="99999999"
                    placeholder="不限"
                >
                <input
                    type="hidden"
                    name="options[{{ $groupIndex }}][values][{{ $optionIndex }}][option_id]"
                    value="{{ data_get($value, 'option_id') }}"
                >
                <button
                    type="button"
                    class="nz-icon-button nz-remove-option"
                    data-id="{{ data_get($value, 'option_id') }}"
                    aria-label="删除选项"
                    title="删除选项"
                >×</button>
            </div>
        @empty
            <div class="nz-option-row add_new_view_row_class" data-option-index="0">
                <input
                    type="text"
                    name="options[{{ $groupIndex }}][values][0][label]"
                    class="form-control"
                    aria-label="选项名称"
                    placeholder="例如：M"
                    required
                >
                <input
                    type="number"
                    name="options[{{ $groupIndex }}][values][0][optionPrice]"
                    class="form-control"
                    value="0"
                    aria-label="加价"
                    min="0"
                    step="0.01"
                    required
                >
                <input
                    type="number"
                    name="options[{{ $groupIndex }}][values][0][total_stock]"
                    class="form-control stock_disable nz-option-stock"
                    aria-label="限量"
                    min="0"
                    max="99999999"
                    placeholder="不限"
                >
                <button
                    type="button"
                    class="nz-icon-button nz-remove-option"
                    aria-label="删除选项"
                    title="删除选项"
                >×</button>
            </div>
        @endforelse
    </div>

    <button
        type="button"
        class="nz-add-option"
        data-add-option="{{ $groupIndex }}"
    >＋ 新增选项</button>
</div>
