@extends('layouts.admin.app')

@section('title', translate('messages.landing_page_settings'))

@section('content')


<div class="content container-fluid">
    <div class="page-header">
        <div class="d-flex flex-wrap justify-content-between align-items-start">
            <h1 class="page-header-title text-capitalize">
                <div class="card-header-icon d-inline-flex mr-2 img">
                    <img src="{{ dynamicAsset('assets/admin/img/landing-page.png') }}" class="mw-26px" alt="public">
                </div>
                <span>
                    {{ translate('Admin_Landing_Page') }}
                </span>
            </h1>
        </div>
    </div>
    <div class="js-nav-scroller tabs-slide-wrap tabs-slide-language hs-nav-scroller-horizontal mb-20">
        @include('admin-views.landing_page.top_menu.admin_landing_menu')
        <div class="arrow-area">
            <div class="button-prev align-items-center">
                <button type="button"
                    class="btn btn-click-prev mr-auto border-0 btn-primary rounded-circle fs-12 p-2 d-center">
                    <i class="tio-chevron-left fs-24"></i>
                </button>
            </div>
            <div class="button-next align-items-center">
                <button type="button"
                    class="btn btn-click-next ml-auto border-0 btn-primary rounded-circle fs-12 p-2 d-center">
                    <i class="tio-chevron-right fs-24"></i>
                </button>
            </div>
        </div>
    </div>


    <div class="card mb-15 pb-lg-10">
        <div class="card-header">
            <div class="">
                <h3 class="mb-1">{{ translate('Meta Data Setup') }}</h3>
                <p class="mb-0 gray-dark fs-12">
                    {{ translate('Include meta title, description, and image to improve search engine visibility and social media sharing.') }}
                </p>
            </div>
        </div>
        <div class="card-body pb-lg-5">
            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="bg-light2 p-xl-20 p-3 rounded h-100">
                        <div class="card-body p-0">
                            <div class="js-nav-scroller hs-nav-scroller-horizontal tabs-slide-language mb-4">
                                <ul class="nav border-0 nav-tabs">
                                    <li class="nav-item">
                                        <a class="nav-link lang_link active" href="#"
                                            id="default-link">{{ translate('Default') }}</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link lang_link" href="#" id="">English(EN)</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link lang_link" href="#" id="">Bengali - বাংলা(BN)</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link lang_link" href="#" id="">Arabic - العربية(AR)</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link lang_link" href="#" id="">Spanish - español(ES)</a>
                                    </li>
                                </ul>
                                <div class="arrow-area">
                                    <div class="button-prev align-items-center">
                                        <button type="button"
                                            class="btn btn-click-prev mr-auto border-0 btn-primary rounded-circle fs-12 p-2 d-center">
                                            <i class="tio-chevron-left fs-24"></i>
                                        </button>
                                    </div>
                                    <div class="button-next align-items-center">
                                        <button type="button"
                                            class="btn btn-click-next ml-auto border-0 btn-primary rounded-circle fs-12 p-2 d-center">
                                            <i class="tio-chevron-right fs-24"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="lang_form" id="default-form">
                                <div class="form-group">
                                    <label class="input-label fw-400" for="default_title">{{ translate('messages.Meta title') }}
                                        ({{ translate('messages.Default') }})<span class="form-label-secondary text-left"
                                            data-toggle="tooltip" data-placement="right" data-original-title="{{ translate('This title appears in browser tabs, search results, and link
                                            previews. Use a short, clear,
                                            and keyword-focused title
                                            (recommended: 50-60
                                            characters)') }}">
                                            <i class="tio-info text-gray1 fs-16"></i>
                                        </span>
                                    </label>
                                    <input type="text" name="title[]" id="default_title" maxlength="50" class="form-control"
                                        placeholder="{{ translate('messages.Type meta title') }}" value="">
                                    <!-- <div class="d-flex justify-content-end">
                                        <span class="text-body-light text-right d-block mt-1">0/50</span>
                                    </div> -->
                                </div>
                                <div class="form-group mb-0">
                                    <label class="input-label fw-400" for="subtitle">{{ translate('messages.Meta Description') }}
                                        ({{ translate('messages.default') }})<span class="form-label-secondary"
                                            data-toggle="tooltip" data-placement="right"
                                            data-original-title="{{ translate('A brief summary that appears
                                            under your page title in
                                            search results. Keep it
                                            compelling and relevant
                                            (recommended: 120—160
                                            characters)') }}">
                                            <i class="tio-info text-gray1 fs-16"></i>
                                        </span>
                                    </label>
                                    <textarea type="text" rows="1" name="subtitle[]" data-maxlength="100"
                                        placeholder="{{translate('messages.Type a short description for campaign')}}"
                                        class="form-control"></textarea>
                                    <div class="d-flex justify-content-end">
                                        <span class="text-body-light text-right d-block mt-1">0/100</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="p-xxl-20 d-flex align-items-center justify-content-center p-12 global-bg-box text-center rounded h-100">
                        <div class="">
                            <div class="mb-20 text-start">
                                <h5 class="mb-1">
                                    {{ translate('Meta Image') }}
                                    <span class="input-label-secondary text--title" data-toggle="tooltip"
                                        data-placement="right"
                                        data-original-title="{{ translate('This image is used as a
                                        preview thumbnail when the
                                        page link is shared on social
                                        media or messaging
                                        platforms.') }}">
                                        <i class="tio-info text-gray1 fs-16"></i>
                                    </span>
                                </h5>
                                <p class="mb-0 fs-12 gray-dark">{{ translate('Upload your meta image') }}</p>
                            </div>
                            <div class="upload-file  mx-auto">
                                <input type="file" name="image" class="upload-file__input single_file_input"
                                        accept=".webp, .jpg, .jpeg, .png, .gif" required>
                                <label class="upload-file__wrapper ratio-2-1 m-0">
                                    <div class="upload-file-textbox text-center" style="">
                                        <img width="22" class="svg" src="{{dynamicAsset('assets/admin/img/image-upload.png')}}" alt="img">
                                        <h6 class="mt-1 text-gray1 fw-medium fs-10 lh-base text-center">
                                            <span class="text-info">{{translate('Click to upload')}}</span>
                                            <br>
                                            {{translate('Or drag and drop')}}
                                        </h6>
                                    </div>
                                    <img class="upload-file-img" loading="lazy" src="" data-default-src="" alt="" style="display: none;">
                                </label>
                                <div class="overlay">
                                    <div class="d-flex gap-1 justify-content-center align-items-center h-100">
                                        <button type="button" class="btn btn-outline-info icon-btn view_btn">
                                            <i class="tio-invisible"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-info icon-btn edit_btn">
                                            <i class="tio-edit"></i>
                                        </button>
                                        <button type="button" class="remove_btn btn icon-btn">
                                            <i class="tio-delete text-danger"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <p class="fs-10 text-center mb-0 mt-20">
                                {{ translate('JPG, JPEG, PNG, Gif Image size : Max 2 MB')}} <span class="font-medium text-title">{{ translate('(2:1)')}}</span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="btn--container justify-content-end mt-4">
                <button type="reset" class="btn btn--reset">{{translate('Reset')}}</button>
                <button type="submit" class="btn btn--primary">{{translate('save')}}</button>
            </div>
        </div>
    </div>

</div>





@endsection

