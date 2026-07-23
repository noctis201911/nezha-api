@php
    $isEdit = isset($product);
    $categoryPath = $isEdit ? collect(json_decode($product->category_ids ?? '[]', true)) : collect();
    $primaryCategoryId = data_get($categoryPath->firstWhere('position', 1), 'id');
    $secondaryCategoryId = data_get($categoryPath->firstWhere('position', 2), 'id');
    $selectedAddonIds = $isEdit ? (json_decode($product->add_ons ?? '[]', true) ?: []) : [];
    $selectedNutritionNames = $isEdit ? $product->nutritions->pluck('nutrition')->all() : [];
    $selectedAllergyNames = $isEdit ? $product->allergies->pluck('allergy')->all() : [];
    $variations = $isEdit ? (json_decode($product->variations ?? '[]', true) ?: []) : [];
    $nextVariationIndex = count($variations) + 1;
@endphp

<form
    action="javascript:"
    method="post"
    id="food_form"
    class="nz-product-form"
    enctype="multipart/form-data"
    data-submit-url="{{ $isEdit ? route('vendor.food.update', [$product->id]) : route('vendor.food.store') }}"
    data-success-url="{{ route('vendor.food.list') }}"
    data-next-variation-index="{{ $nextVariationIndex }}"
>
    @csrf
    <input type="hidden" name="lang[]" value="default">
    <input type="hidden" id="restaurant_id" value="{{ $restaurantId }}">
    <input type="hidden" id="request_type" value="vendor">
    <input type="hidden" id="removedVariationIDs" name="removedVariationIDs" value="">
    <input type="hidden" id="removedVariationOptionIDs" name="removedVariationOptionIDs" value="">
    <input type="hidden" name="remove_all_old_variations" value="0" id="remove_all_old_variations">

    <section class="nz-form-card" aria-labelledby="nz-basic-title">
        <div class="nz-form-card__header">
            <div>
                <h2 id="nz-basic-title">① 基础信息</h2>
                <p>名称、图片、分类与顾客看到的一句话介绍</p>
            </div>
            <span>必填 2 项</span>
        </div>
        <div class="nz-form-card__body nz-basic-grid">
            <div class="nz-basic-fields">
                <div class="form-group">
                    <label class="input-label" for="default_name">
                        商品名称 <span class="nz-required">*</span>
                    </label>
                    <input
                        type="text"
                        name="name[]"
                        id="default_name"
                        class="form-control"
                        maxlength="191"
                        value="{{ old('name.0', $isEdit ? $product->getRawOriginal('name') : '') }}"
                        placeholder="例如：经典波霸奶茶"
                        required
                    >
                </div>

                <div class="nz-two-column">
                    <div class="form-group">
                        <label class="input-label" for="category_id">
                            所属分类 <span class="nz-required">*</span>
                        </label>
                        <select
                            name="category_id"
                            id="category_id"
                            class="form-control js-select2-custom"
                            data-url="{{ url('/restaurant-panel/food/get-categories?parent_id=') }}"
                            required
                        >
                            <option value="" disabled {{ $primaryCategoryId ? '' : 'selected' }}>请选择菜品分类</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected((int) $primaryCategoryId === (int) $category->id)>
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group nz-subcategory-field {{ $secondaryCategoryId ? '' : 'd-none' }}">
                        <label class="input-label" for="sub-categories">二级分类（选填）</label>
                        <select
                            name="sub_category_id"
                            id="sub-categories"
                            class="form-control js-select2-custom"
                            data-selected="{{ $secondaryCategoryId }}"
                        >
                            <option value="">不选二级分类</option>
                        </select>
                    </div>
                </div>

                <div class="form-group mb-0">
                    <label class="input-label" for="description-default">简短描述（选填）</label>
                    <textarea
                        name="description[]"
                        id="description-default"
                        class="form-control"
                        maxlength="600"
                        rows="3"
                        placeholder="一句话介绍，顾客会在菜品卡和详情里看到"
                    >{{ old('description.0', $isEdit ? $product->getRawOriginal('description') : '') }}</textarea>
                </div>
            </div>

            <div class="nz-image-field">
                <p class="input-label">商品图（选填 · 1:1）</p>
                @include('admin-views.partials._image-uploader', [
                    'id' => 'image-input',
                    'name' => 'image',
                    'isRequired' => false,
                    'existingImage' => $isEdit ? $product['image_full_url'] : null,
                    'ratio' => '1:1',
                    'imageExtension' => IMAGE_EXTENSION,
                    'imageFormat' => IMAGE_FORMAT,
                    'maxSize' => 1,
                ])
            </div>
        </div>
    </section>

    <section class="nz-form-card" aria-labelledby="nz-price-title">
        <div class="nz-form-card__header">
            <div>
                <h2 id="nz-price-title">② 价格与规格</h2>
                <p>规格差价在商品单价上累加，0 表示不加价</p>
            </div>
            <span>单价必填</span>
        </div>
        <div class="nz-form-card__body">
            <div class="nz-price-row">
                <div class="form-group">
                    <label class="input-label" for="unit_price">
                        单价（{{ \App\CentralLogics\Helpers::currency_symbol() }}）
                        <span class="nz-required">*</span>
                    </label>
                    <input
                        type="number"
                        id="unit_price"
                        name="price"
                        class="form-control"
                        min="0.01"
                        max="999999999999.99"
                        step="0.01"
                        value="{{ old('price', $isEdit ? $product->price : '') }}"
                        placeholder="例如：1500"
                        required
                    >
                </div>
                <div class="form-group">
                    <label class="input-label" for="discount">折扣（默认无）</label>
                    <div class="nz-inline-fields">
                        <input
                            type="number"
                            id="discount"
                            name="discount"
                            class="form-control"
                            min="0"
                            step="0.01"
                            value="{{ old('discount', $isEdit ? $product->discount : 0) }}"
                        >
                        <select name="discount_type" id="discount_type" class="form-control">
                            <option value="percent" @selected(!$isEdit || $product->discount_type === 'percent')>百分比 %</option>
                            <option value="amount" @selected($isEdit && $product->discount_type === 'amount')>固定金额</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="nz-template-row" aria-label="快捷添加规格组">
                <span>快捷添加规格组：</span>
                <button type="button" class="nz-template-chip" data-variation-template="cup">＋杯型 M / L / XL</button>
                <button type="button" class="nz-template-chip" data-variation-template="portion">＋份量 小份 / 大份</button>
                <button type="button" class="nz-template-chip" data-variation-template="spicy">＋辣度 不辣 / 微辣 / 中辣 / 特辣</button>
                <button type="button" class="nz-template-chip nz-template-chip--custom" data-variation-template="custom">＋自定义规格组</button>
            </div>

            <div id="add_new_option" class="nz-variation-list" aria-live="polite">
                @foreach ($variations as $variationIndex => $item)
                    @continue(isset($item['price']))
                    @include('vendor-views.product.partials._variation-group-redesign', [
                        'item' => $item,
                        'groupIndex' => $variationIndex + 1,
                    ])
                @endforeach
            </div>

            <div id="empty-variation" class="nz-empty-inline {{ count($variations) ? 'd-none' : '' }}">
                暂无规格。常见杯型、份量和辣度可用上方模板一键生成。
            </div>
        </div>
    </section>

    <section class="nz-form-card" aria-labelledby="nz-addon-title">
        <div class="nz-form-card__header">
            <div>
                <h2 id="nz-addon-title">③ 加料（可加多份的附加项）</h2>
                <p>改变菜品本体的选择用“规格”；额外加东西、可加多份、要挂多道菜时用“加料”。</p>
            </div>
        </div>
        <div class="nz-form-card__body nz-addon-row">
            <div class="form-group mb-0">
                <label class="input-label" for="add_on">从加料库选择</label>
                <select name="addon_ids[]" class="form-control multiple-select2" multiple id="add_on">
                    @foreach ($addons as $addon)
                        <option value="{{ $addon->id }}" @selected(in_array($addon->id, $selectedAddonIds))>
                            {{ $addon->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <a class="nz-secondary-action" href="{{ route('vendor.addon.add-new') }}" target="_blank" rel="noopener">
                新建加料 ›
            </a>
        </div>
    </section>

    <details class="nz-form-card nz-advanced-card">
        <summary>
            <span><strong>④ 高级设置</strong>　售卖时段、库存、购买上限、饮食标签与搜索标签</span>
            <span>都有省心默认值，不填也能上架</span>
        </summary>
        <div class="nz-form-card__body nz-advanced-grid">
            <div class="form-group">
                <label class="input-label" for="available_time_starts">每日开售（选填 · 空=全天）</label>
                <input
                    type="time"
                    name="available_time_starts"
                    id="available_time_starts"
                    class="form-control"
                    value="{{ old('available_time_starts', $isEdit ? $product->available_time_starts : '') }}"
                >
            </div>
            <div class="form-group">
                <label class="input-label" for="available_time_ends">每日停售（选填 · 空=全天）</label>
                <input
                    type="time"
                    name="available_time_ends"
                    id="available_time_ends"
                    class="form-control"
                    value="{{ old('available_time_ends', $isEdit ? $product->available_time_ends : '') }}"
                >
            </div>
            <div class="form-group">
                <label class="input-label" for="stock_type">库存类型</label>
                <select name="stock_type" id="stock_type" class="form-control">
                    <option value="unlimited" @selected(!$isEdit || $product->stock_type === 'unlimited')>无限库存</option>
                    <option value="limited" @selected($isEdit && $product->stock_type === 'limited')>固定库存</option>
                    <option value="daily" @selected($isEdit && $product->stock_type === 'daily')>每日库存</option>
                </select>
            </div>
            <div class="form-group nz-stock-field">
                <label class="input-label" for="item_stock">商品库存</label>
                <input
                    type="number"
                    name="item_stock"
                    id="item_stock"
                    class="form-control stock_disable"
                    min="0"
                    max="999999999"
                    value="{{ old('item_stock', $isEdit ? $product->item_stock : '') }}"
                    placeholder="不限"
                >
            </div>
            <div class="form-group">
                <label class="input-label" for="cart_quantity">单次购买上限（选填）</label>
                <input
                    type="number"
                    name="maximum_cart_quantity"
                    id="cart_quantity"
                    class="form-control"
                    min="0"
                    value="{{ old('maximum_cart_quantity', $isEdit ? $product->maximum_cart_quantity : '') }}"
                    placeholder="不限制"
                >
            </div>
            <div class="form-group">
                <label class="input-label" for="veg">饮食类型</label>
                <select name="veg" id="veg" class="form-control">
                    <option value="0" @selected(!$isEdit || (int) $product->veg === 0)>非素食</option>
                    <option value="1" @selected($isEdit && (int) $product->veg === 1)>素食</option>
                </select>
            </div>
            <div class="form-group">
                <label class="input-label" for="nutritions_input">营养标签（选填）</label>
                <select name="nutritions[]" class="form-control multiple-select2" id="nutritions_input" multiple>
                    @foreach ($nutritions as $nutrition)
                        <option value="{{ $nutrition->nutrition }}" @selected(in_array($nutrition->nutrition, $selectedNutritionNames))>
                            {{ $nutrition->nutrition }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label class="input-label" for="allergy_input">过敏原（选填）</label>
                <select name="allergies[]" class="form-control multiple-select2" id="allergy_input" multiple>
                    @foreach ($allergies as $allergy)
                        <option value="{{ $allergy->allergy }}" @selected(in_array($allergy->allergy, $selectedAllergyNames))>
                            {{ $allergy->allergy }}
                        </option>
                    @endforeach
                </select>
            </div>
            @if ($productWiseTax)
                <div class="form-group">
                    <label class="input-label">商品税项（选填）</label>
                    <select name="tax_ids[]" class="form-control multiple-select2" multiple>
                        @foreach ($taxVats as $taxVat)
                            <option value="{{ $taxVat->id }}" @selected($isEdit && in_array($taxVat->id, $taxVatIds ?? []))>
                                {{ $taxVat->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="form-group">
                <label class="input-label" for="tags">搜索标签（选填）</label>
                <input
                    type="text"
                    class="form-control"
                    id="tags"
                    name="tags"
                    value="{{ $isEdit ? $product->tags->pluck('tag')->implode(',') : '' }}"
                    placeholder="奶茶, 招牌, 微辣"
                    data-role="tagsinput"
                >
            </div>
            <div class="nz-toggle-row">
                <label class="form-check form--check">
                    <input class="form-check-input" name="is_halal" type="checkbox" value="1" @checked($isEdit && (int) $product->is_halal === 1)>
                    <span class="form-check-label">清真</span>
                </label>
                <label class="form-check form--check">
                    <input type="hidden" name="status" value="0">
                    <input class="form-check-input" name="status" type="checkbox" value="1" @checked(!$isEdit || (int) $product->status === 1)>
                    <span class="form-check-label">启用商品</span>
                </label>
            </div>
        </div>
    </details>

    <div class="nz-form-actions">
        <button type="reset" id="reset_btn" class="btn nz-reset-button">重置</button>
        <button type="submit" id="submit_btn" class="btn nz-submit-button">
            <span class="nz-submit-label">{{ $isEdit ? '保存修改' : '提交' }}</span>
            <span class="nz-submit-loading d-none">提交中...</span>
        </button>
    </div>
</form>
