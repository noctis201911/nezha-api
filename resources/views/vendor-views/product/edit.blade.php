@extends('layouts.vendor.app')

@section('title', translate('messages.edit_food'))

@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link href="{{ dynamicAsset('assets/admin/css/tags-input.min.css') }}" rel="stylesheet">
    @include('vendor-views.product.partials._form-redesign-styles')
@endpush

@section('content')
    <div class="content container-fluid nz-product-page">
        <div class="page-header">
            <h1 class="page-header-title">
                <i class="tio-edit"></i>
                编辑商品
            </h1>
        </div>

        @include('vendor-views.product.partials._form-redesign')
    </div>
@endsection

@push('script_2')
    <script src="{{ dynamicAsset('assets/admin/js/tags-input.min.js') }}"></script>
    <script src="{{ dynamicAsset('assets/admin/js/AI/products/compressor/image-compressor.js') }}"></script>
    <script src="{{ dynamicAsset('assets/admin/js/AI/products/compressor/compressor.min.js') }}"></script>
    @include('vendor-views.product.partials._form-redesign-scripts')
@endpush
