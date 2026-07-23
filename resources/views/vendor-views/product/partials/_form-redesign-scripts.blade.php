<script>
    (() => {
        'use strict';

        const form = document.getElementById('food_form');
        if (!form) {
            return;
        }

        if (window.jQuery?.fn?.select2) {
            $('.multiple-select2, .js-select2-custom').select2({ width: '100%' });
        }

        const variationList = document.getElementById('add_new_option');
        const emptyVariation = document.getElementById('empty-variation');
        const removedVariationIDs = [];
        const removedVariationOptionIDs = [];
        const templates = {
            cup: { name: '杯型', values: ['M', 'L', 'XL'] },
            portion: { name: '份量', values: ['小份', '大份'] },
            spicy: { name: '辣度', values: ['不辣', '微辣', '中辣', '特辣'] },
            custom: { name: '', values: [''] },
        };

        const escapeHtml = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const optionRow = (groupIndex, optionIndex, option = {}) => `
            <div class="nz-option-row add_new_view_row_class" data-option-index="${optionIndex}">
                <input
                    type="text"
                    name="options[${groupIndex}][values][${optionIndex}][label]"
                    class="form-control"
                    value="${escapeHtml(option.label)}"
                    aria-label="选项名称"
                    placeholder="例如：M"
                    required
                >
                <input
                    type="number"
                    name="options[${groupIndex}][values][${optionIndex}][optionPrice]"
                    class="form-control"
                    value="${escapeHtml(option.optionPrice ?? 0)}"
                    aria-label="加价"
                    min="0"
                    step="0.01"
                    required
                >
                <input
                    type="number"
                    name="options[${groupIndex}][values][${optionIndex}][total_stock]"
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
        `;

        const variationGroup = (groupIndex, template) => {
            const values = template.values.length ? template.values : [''];
            return `
                <div class="nz-variation-group view_new_option" data-variation-index="${groupIndex}">
                    <div class="nz-variation-group__top">
                        <label class="form-check form--check nz-required-toggle">
                            <input
                                class="form-check-input nz-required-checkbox"
                                name="options[${groupIndex}][required]"
                                type="checkbox"
                                value="on"
                                checked
                            >
                            <span class="form-check-label">顾客必选</span>
                        </label>
                        <button type="button" class="nz-icon-button nz-remove-variation" aria-label="删除规格组" title="删除规格组">×</button>
                    </div>
                    <div class="nz-variation-head-grid">
                        <div class="form-group mb-0">
                            <label class="input-label">规格组名称 <span class="nz-required">*</span></label>
                            <input
                                type="text"
                                name="options[${groupIndex}][name]"
                                class="form-control new_option_name"
                                value="${escapeHtml(template.name)}"
                                placeholder="例如：杯型"
                                required
                            >
                        </div>
                        <fieldset class="nz-choice-mode">
                            <legend>顾客怎么选</legend>
                            <div class="nz-segmented">
                                <label>
                                    <input type="radio" name="options[${groupIndex}][type]" value="single" checked>
                                    <span>单选一项</span>
                                </label>
                                <label>
                                    <input type="radio" name="options[${groupIndex}][type]" value="multi">
                                    <span>可选多项</span>
                                </label>
                            </div>
                        </fieldset>
                        <div class="nz-min-max d-none">
                            <div class="form-group mb-0">
                                <label class="input-label">至少选</label>
                                <input type="number" name="options[${groupIndex}][min]" class="form-control nz-min-input" min="0" value="1">
                            </div>
                            <div class="form-group mb-0">
                                <label class="input-label">最多选</label>
                                <input type="number" name="options[${groupIndex}][max]" class="form-control nz-max-input" min="1" value="1">
                            </div>
                        </div>
                    </div>
                    <div class="nz-option-columns" aria-hidden="true">
                        <span>选项名称</span>
                        <span>加价（{{ \App\CentralLogics\Helpers::currency_symbol() }}）· 0 = 不加价</span>
                        <span>限量</span>
                        <span></span>
                    </div>
                    <div class="nz-option-list" id="option_price_view_${groupIndex}">
                        ${values.map((label, index) => optionRow(groupIndex, index, { label })).join('')}
                    </div>
                    <button type="button" class="nz-add-option" data-add-option="${groupIndex}">＋ 新增选项</button>
                </div>
            `;
        };

        const syncEmptyState = () => {
            emptyVariation.classList.toggle(
                'd-none',
                variationList.querySelectorAll('.nz-variation-group').length > 0
            );
        };

        const syncGroupRules = (group) => {
            const type = group.querySelector('input[type="radio"][value="multi"]:checked')
                ? 'multi'
                : 'single';
            const minMax = group.querySelector('.nz-min-max');
            const minInput = group.querySelector('.nz-min-input');
            const maxInput = group.querySelector('.nz-max-input');
            const required = group.querySelector('.nz-required-checkbox')?.checked;
            const optionCount = group.querySelectorAll('.nz-option-row').length;

            minMax.classList.toggle('d-none', type === 'single');
            if (type === 'single') {
                minInput.value = 1;
                maxInput.value = 1;
                return;
            }

            const currentMin = Number.parseInt(minInput.value || '0', 10);
            minInput.value = required ? Math.max(1, currentMin) : Math.max(0, currentMin);
            maxInput.value = Math.max(
                Number.parseInt(minInput.value || '0', 10),
                Number.parseInt(maxInput.value || '0', 10),
                optionCount
            );
        };

        const syncStockFields = () => {
            const unlimited = document.getElementById('stock_type').value === 'unlimited';
            document.querySelectorAll('.stock_disable').forEach((input) => {
                input.readOnly = unlimited;
                input.required = !unlimited;
                if (unlimited) {
                    input.value = '';
                    input.placeholder = '不限';
                }
            });
            document.querySelector('.nz-stock-field')?.classList.toggle('d-none', unlimited);
        };

        document.querySelectorAll('[data-variation-template]').forEach((button) => {
            button.addEventListener('click', () => {
                const template = templates[button.dataset.variationTemplate];
                const groupIndex = Number.parseInt(form.dataset.nextVariationIndex, 10);
                form.dataset.nextVariationIndex = String(groupIndex + 1);
                variationList.insertAdjacentHTML('beforeend', variationGroup(groupIndex, template));
                const group = variationList.lastElementChild;
                syncGroupRules(group);
                syncStockFields();
                syncEmptyState();
                group.querySelector('input[name$="[name]"]')?.focus();
            });
        });

        variationList.addEventListener('click', (event) => {
            const addOptionButton = event.target.closest('[data-add-option]');
            if (addOptionButton) {
                const group = addOptionButton.closest('.nz-variation-group');
                const groupIndex = group.dataset.variationIndex;
                const optionIndexes = Array.from(group.querySelectorAll('.nz-option-row'))
                    .map((row) => Number.parseInt(row.dataset.optionIndex, 10));
                const nextOptionIndex = optionIndexes.length
                    ? Math.max(...optionIndexes) + 1
                    : 0;
                group.querySelector('.nz-option-list').insertAdjacentHTML(
                    'beforeend',
                    optionRow(groupIndex, nextOptionIndex)
                );
                syncGroupRules(group);
                syncStockFields();
                group.querySelector('.nz-option-row:last-child input')?.focus();
                return;
            }

            const removeOptionButton = event.target.closest('.nz-remove-option');
            if (removeOptionButton) {
                const group = removeOptionButton.closest('.nz-variation-group');
                if (group.querySelectorAll('.nz-option-row').length <= 1) {
                    toastr.warning('每个规格组至少保留一个选项');
                    return;
                }
                if (removeOptionButton.dataset.id) {
                    removedVariationOptionIDs.push(removeOptionButton.dataset.id);
                    document.getElementById('removedVariationOptionIDs').value =
                        removedVariationOptionIDs.join(',');
                }
                removeOptionButton.closest('.nz-option-row').remove();
                syncGroupRules(group);
                return;
            }

            const removeVariationButton = event.target.closest('.nz-remove-variation');
            if (removeVariationButton) {
                if (removeVariationButton.dataset.id) {
                    removedVariationIDs.push(removeVariationButton.dataset.id);
                    document.getElementById('removedVariationIDs').value =
                        removedVariationIDs.join(',');
                }
                removeVariationButton.closest('.nz-variation-group').remove();
                syncEmptyState();
            }
        });

        variationList.addEventListener('change', (event) => {
            if (
                event.target.matches('input[type="radio"][name$="[type]"]') ||
                event.target.matches('.nz-required-checkbox')
            ) {
                syncGroupRules(event.target.closest('.nz-variation-group'));
            }
        });

        const category = document.getElementById('category_id');
        const subCategory = document.getElementById('sub-categories');
        const subCategoryField = document.querySelector('.nz-subcategory-field');
        const loadSubcategories = async () => {
            if (!category.value) {
                subCategoryField.classList.add('d-none');
                return;
            }

            const selected = subCategory.dataset.selected || '';
            const response = await fetch(
                `${category.dataset.url}${encodeURIComponent(category.value)}&sub_category=${encodeURIComponent(selected)}`,
                { headers: { Accept: 'application/json' } }
            );
            if (!response.ok) {
                throw new Error('subcategory-request-failed');
            }
            const data = await response.json();
            subCategory.innerHTML = data.options;
            subCategory.insertAdjacentHTML('afterbegin', '<option value="">不选二级分类</option>');
            const hasOptions = subCategory.querySelectorAll('option').length > 2;
            subCategoryField.classList.toggle('d-none', !hasOptions);
            subCategory.dataset.selected = '';
        };

        category.addEventListener('change', () => {
            loadSubcategories().catch(() => toastr.error('二级分类加载失败，请重试'));
        });
        if (category.value) {
            loadSubcategories().catch(() => {});
        }

        document.getElementById('stock_type').addEventListener('change', syncStockFields);
        document.querySelectorAll('.nz-variation-group').forEach(syncGroupRules);
        syncStockFields();
        syncEmptyState();

        document.getElementById('reset_btn').addEventListener('click', (event) => {
            event.preventDefault();
            window.location.reload();
        });

        const setSubmitting = (submitting) => {
            const button = document.getElementById('submit_btn');
            button.disabled = submitting;
            button.querySelector('.nz-submit-label').classList.toggle('d-none', submitting);
            button.querySelector('.nz-submit-loading').classList.toggle('d-none', !submitting);
        };

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            if (!form.checkValidity()) {
                const invalid = form.querySelector(':invalid');
                invalid?.closest('details')?.setAttribute('open', '');
                form.reportValidity();
                invalid?.focus();
                return;
            }

            const image = document.getElementById('image-input')?.files?.[0];
            if (image && image.size > 1024 * 1024) {
                toastr.error('商品图不能超过 1MB');
                return;
            }

            const formData = new FormData(form);
            setSubmitting(true);
            $.ajax({
                url: form.dataset.submitUrl,
                type: 'POST',
                data: formData,
                cache: false,
                contentType: false,
                processData: false,
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                success(data) {
                    if (data.errors) {
                        data.errors.forEach((error) => toastr.error(error.message));
                        setSubmitting(false);
                        return;
                    }

                    toastr.success('{{ $isEdit ? '商品修改成功' : '商品创建成功' }}');
                    window.setTimeout(() => {
                        window.location.href = form.dataset.successUrl;
                    }, 700);
                },
                error(xhr) {
                    const message = xhr.responseJSON?.message || '提交失败，请稍后重试';
                    toastr.error(message);
                    setSubmitting(false);
                },
            });
        });
    })();
</script>
