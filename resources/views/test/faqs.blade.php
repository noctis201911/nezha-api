@extends('layouts.admin.app')

@section('title', translate('messages.landing_page_settings'))

@section('content')


<div class="content container-fluid">
    <div class="page-header">
        <div class="d-flex flex-wrap justify-content-between align-items-start">
            <h1 class="page-header-title text-capitalize fs-24">
                <div class="card-header-icon d-inline-flex mr-2 img">
                    <img src="{{ dynamicAsset('assets/admin/img/landing-page.png') }}" class="mw-26px" alt="public">
                </div>
                <span>
                    {{ translate('React_Landing_Page') }}
                </span>
            </h1>
        </div>
    </div>
    <div class="mb-15">
        <div class="js-nav-scroller tabs-slide-wrap tabs-slide-language hs-nav-scroller-horizontal">
            @include('admin-views.landing_page.top_menu.react_landing_menu')
            <div class="arrow-area">
                <div class="button-prev align-items-center">
                    <button type="button" class="btn btn-click-prev mr-auto border-0 btn-primary rounded-circle fs-12 p-2 d-center">
                        <i class="tio-chevron-left fs-24"></i>
                    </button>
                </div>
                <div class="button-next align-items-center">
                    <button type="button" class="btn btn-click-next ml-auto border-0 btn-primary rounded-circle fs-12 p-2 d-center">
                        <i class="tio-chevron-right fs-24"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <div class="card py-3 px-xxl-4 px-3 mb-15 mt-4">
        <div class="row g-3 align-items-center justify-content-between">
            <div class="col-xxl-9 col-lg-8 col-md-7 col-sm-6">
                <div class="">
                    <h3 class="mb-1">{{ translate('Show_on_Website') }}</h3>
                    <p class="mb-0 gray-dark fs-12">If you turn of the availability status, this section will not show in the website</p>
                </div>
            </div>
            <div class="col-xxl-3 col-lg-4 col-md-5 col-sm-6">
                <div class="py-2 px-3 rounded d-flex justify-content-between border align-items-center w-300">
                    <h5 class="text-capitalize fw-normal mb-0">{{translate('Status') }}</h5>
                    <label class="toggle-switch toggle-switch-sm">
                        <input type="checkbox" id="" class="status toggle-switch-input" checked>
                        <span class="toggle-switch-label text">
                            <span class="toggle-switch-indicator"></span>
                        </span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-15">
        <div class="card-header">
            <div class="">
                <h3 class="mb-1">{{ translate('FAQ Section') }}</h3>
                <p class="mb-0 gray-dark fs-12">{{ translate('Lorem ipsum dolor sit amet, consectetur adipiscing elit. Aliquam odio tellus, laoreet ') }}</p>
            </div>
        </div>
        <div class="card-body">
            <div class="card-custom-xl mb-15">
                <div class="bg-light2 p-xl-20 p-3 rounded">
                    <div class="card-body p-0 mb-3">
                        <div class="js-nav-scroller hs-nav-scroller-horizontal">
                            <ul class="nav nav-tabs mb-4">
                                <li class="nav-item">
                                    <a class="nav-link lang_link active" href="#"id="default-link">{{ translate('Default') }}</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link lang_link"href="#" id="">English(EN)</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link lang_link"href="#" id="">Bengali - বাংলা(BN)</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link lang_link"href="#" id="">Arabic - العربية(AR)</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link lang_link"href="#" id="">Spanish - español(ES)</a>
                                </li>
                            </ul>
                        </div>
                        <div class="lang_form" id="default-form">
                            <div class="form-group mb-3">
                                <label class="input-label fw-400" for="default_title">{{ translate('messages.title') }}
                                        ({{ translate('messages.Default') }})<span class="form-label-secondary" data-toggle="tooltip" data-placement="right" data-original-title="{{ translate('content..l') }}">
                                            <i class="tio-info text-gray1 fs-16"></i>
                                        </span>
                                </label>
                                <input type="text" name="title[]" id="default_title" maxlength="20"
                                        class="form-control" placeholder="{{ translate('messages.Enjoy Fresh Food') }}" value=""
                                >
                                <div class="d-flex justify-content-end">
                                    <span class="text-body-light text-right d-block mt-1">0/20</span>
                                </div>
                            </div>
                            <div class="form-group mb-0">
                                <label class="input-label fw-400" for="subtitle">{{ translate('messages.Subtitle') }} ({{ translate('messages.default') }})<span class="form-label-secondary" data-toggle="tooltip" data-placement="right" data-original-title="{{ translate('Write_the_subtitle_within_20_characters') }}">
                                            <i class="tio-info text-gray1 fs-16"></i>
                                        </span>
                                </label>
                                <textarea type="text" rows="1" name="subtitle[]" data-maxlength="60" placeholder="{{translate('messages.Lorem ipsum dolor sit amet, consectetur adipiscing elit.')}}" class="form-control"></textarea>
                                <div class="d-flex justify-content-end">
                                    <span class="text-body-light text-right d-block mt-1">0/60</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="btn--container justify-content-end mt-4">
                    <button type="reset" class="btn btn--reset">{{translate('Reset')}}</button>
                    <button type="submit"   class="btn btn--primary">{{translate('save')}}</button>
                </div>
            </div>
            <div class="card-custom-static p-xxl-4 p-3 mb-15">
                <div class="mb-20">
                    <h4 class="mb-1">{{ translate('FAQ Q&A Setup') }}</h4>
                    <p class="mb-0 gray-dark fs-12">{{ translate('Lorem ipsum dolor sit amet, consectetur adipiscing elit. Aliquam odio tellus, laoreet ') }}</p>
                </div>
                <div class="form-group mb-20">
                    <label class="input-label fw-400">{{ translate('messages.title') }}
                            ({{ translate('messages.User Type') }})
                    </label>
                    <select name="" class="custom-select" id="">
                        <option value="1">
                            Select user type
                        </option>
                        <option value="1">
                            Option 01
                        </option>
                        <option value="1">
                            Option 02
                        </option>
                        <option value="1">
                            Option 03
                        </option>
                    </select>
                </div>
                <div class="bg-light2 p-xl-20 p-3 rounded">
                    <div class="card-body p-0">
                        <div class="js-nav-scroller hs-nav-scroller-horizontal">
                            <ul class="nav nav-tabs mb-4">
                                <li class="nav-item">
                                    <a class="nav-link lang_link active" href="#"id="default-link">{{ translate('Default') }}</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link lang_link"href="#" id="">English(EN)</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link lang_link"href="#" id="">Bengali - বাংলা(BN)</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link lang_link"href="#" id="">Arabic - العربية(AR)</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link lang_link"href="#" id="">Spanish - español(ES)</a>
                                </li>
                            </ul>
                        </div>
                        <div class="lang_form" id="default-form">
                            <div class="form-group mb-3">
                                <label class="input-label fw-400" for="default_title">{{ translate('messages.title') }}
                                        ({{ translate('messages.Default') }})<span class="form-label-secondary" data-toggle="tooltip" data-placement="right" data-original-title="{{ translate('content..l') }}">
                                            <i class="tio-info text-gray1 fs-16"></i>
                                        </span>
                                </label>
                                <input type="text" name="title[]" id="default_title" maxlength="30"
                                        class="form-control" placeholder="{{ translate('messages.Enjoy Fresh Food') }}" value=""
                                >
                                <div class="d-flex justify-content-end">
                                    <span class="text-body-light text-right d-block mt-1">0/30</span>
                                </div>
                            </div>
                            <div class="form-group mb-0">
                                <label class="input-label fw-400" for="subtitle">{{ translate('messages.Subtitle') }} ({{ translate('messages.default') }})<span class="form-label-secondary" data-toggle="tooltip" data-placement="right" data-original-title="{{ translate('Write_the_subtitle_within_20_characters') }}">
                                            <i class="tio-info text-gray1 fs-16"></i>
                                        </span>
                                </label>
                                <textarea type="text" rows="1" name="subtitle[]" data-maxlength="100" placeholder="{{translate('messages.Lorem ipsum dolor sit amet, consectetur adipiscing elit.')}}" class="form-control"></textarea>
                                <div class="d-flex justify-content-end">
                                    <span class="text-body-light text-right d-block mt-1">0/100</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="btn--container justify-content-end mt-4">
                    <button type="reset" class="btn btn--reset">{{translate('Reset')}}</button>
                    <button type="button"   class="btn btn--primary">{{translate('Add')}}</button>
                </div>
            </div>
            <div class="card-custom-static">
                <div class="card-header border-0 p-20">
                    <div class="search--button-wrapper">
                        <h5 class="card-title d-flex align-items-center">{{translate('messages.FAQ Q/A List')}}</h5>
                        <form class="search-form">
                            <div class="input-group input--group">
                                <input id="datatableSearch_" type="search" name="search" value="{{ request()?->search ?? null }}" class="form-control"
                                        placeholder="{{translate('Search_title')}}" aria-label="{{translate('messages.search')}}" >
                                <button type="submit" class="btn btn--secondary secondary-cmn"><i class="tio-search"></i></button>

                            </div>
                        </form>
                    </div>
                </div>
                <div class="card-body p-20 pt-0">
                    <div class="table-responsive datatable-custom py-0">
                        <table class="table table-borderless table-thead-borderless table-align-middle table-nowrap card-table">
                            <thead class="thead-light border-0">
                                <tr>
                                    <th class="border-top-0">Sl</th>
                                    <th class="border-top-0">Question</th>
                                    <th class="border-top-0">Answer </th>
                                    <th class="border-top-0">User Type</th>
                                    <th class="border-top-0">Status</th>
                                    <th class="text-center border-top-0">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>1</td>
                                    <td>
                                        <div class="text--title word-break min-w-100px line-limit-2 max-w-220px text-wrap">
                                            Lorem ipsum dolor sit amet, conse ctetur?
                                        </div>
                                    </td>
                                    <td>
                                        <div class="word-break min-w-170px line-limit-3 max-w-300 text-wrap">
                                            Lorem ipsum dolor sit amet, Lorem ipsum dolor sit amet, Lorem ipsum dolor sit amet, Lorem ipsum dolor sit amet, Lorem ipsum dolor sit amet,
                                        </div>
                                    </td>
                                    <td>
                                       Customer
                                    </td>
                                    <td>
                                        <label class="toggle-switch toggle-switch-sm">
                                            <input type="checkbox" class="status toggle-switch-input" id="" checked="">
                                            <span class="toggle-switch-label">
                                                <span class="toggle-switch-indicator"></span>
                                            </span>
                                        </label>
                                    </td>
                                    <td>
                                        <div class="btn--container justify-content-center">
                                            <a class="btn action-btn btn--primary btn-outline-primary" href="">
                                                <i class="tio-edit"></i>
                                            </a>
                                            <a class="btn action-btn btn--danger btn-outline-danger" href="javascript:" title="">
                                                <i class="tio-delete-outlined"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>2</td>
                                    <td>
                                        <div class="text--title word-break min-w-100px line-limit-2 max-w-220px text-wrap">
                                            Lorem ipsum dolor sit amet, conse ctetur?
                                        </div>
                                    </td>
                                    <td>
                                        <div class="word-break min-w-170px line-limit-3 max-w-300 text-wrap">
                                            Lorem ipsum dolor sit amet, odio tellus, laoreet.Lorem ipsum dolor sit amet, Lorem ipsum dolor sit amet, Lorem ipsum dolor sit amet,
                                        </div>
                                    </td>
                                    <td>
                                       Restaurant
                                    </td>
                                    <td>
                                        <label class="toggle-switch toggle-switch-sm">
                                            <input type="checkbox" class="status toggle-switch-input" id="" checked="">
                                            <span class="toggle-switch-label">
                                                <span class="toggle-switch-indicator"></span>
                                            </span>
                                        </label>
                                    </td>
                                    <td>
                                        <div class="btn--container justify-content-center">
                                            <a class="btn action-btn btn--primary btn-outline-primary" href="">
                                                <i class="tio-edit"></i>
                                            </a>
                                            <a class="btn action-btn btn--danger btn-outline-danger" href="javascript:" title="">
                                                <i class="tio-delete-outlined"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>3</td>
                                    <td>
                                        <div class="text--title word-break min-w-100px line-limit-2 max-w-220px text-wrap">
                                            Lorem ipsum dolor sit amet, conse ctetur?
                                        </div>
                                    </td>
                                    <td>
                                        <div class="word-break min-w-170px line-limit-3 max-w-300 text-wrap">
                                            Lorem ipsum dolor sit amet, laoreet.
                                        </div>
                                    </td>
                                    <td>
                                       Deliveryman
                                    </td>
                                    <td>
                                        <label class="toggle-switch toggle-switch-sm">
                                            <input type="checkbox" class="status toggle-switch-input" id="" checked="">
                                            <span class="toggle-switch-label">
                                                <span class="toggle-switch-indicator"></span>
                                            </span>
                                        </label>
                                    </td>
                                    <td>
                                        <div class="btn--container justify-content-center">
                                            <a class="btn action-btn btn--primary btn-outline-primary" href="">
                                                <i class="tio-edit"></i>
                                            </a>
                                            <a class="btn action-btn btn--danger btn-outline-danger" href="javascript:" title="">
                                                <i class="tio-delete-outlined"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>4</td>
                                    <td>
                                        <div class="text--title word-break min-w-100px line-limit-2 max-w-220px text-wrap">
                                            Lorem ipsum dolor sit amet, conse ctetur?
                                        </div>
                                    </td>
                                    <td>
                                        <div class="word-break min-w-170px line-limit-3 max-w-300 text-wrap">
                                            Lorem ipsum dolor sit amet, consectetur adipiscing elit. Aliquam odio tellus, laoreet.
                                        </div>
                                    </td>
                                    <td>
                                       Customer
                                    </td>
                                    <td>
                                        <label class="toggle-switch toggle-switch-sm">
                                            <input type="checkbox" class="status toggle-switch-input" id="" checked="">
                                            <span class="toggle-switch-label">
                                                <span class="toggle-switch-indicator"></span>
                                            </span>
                                        </label>
                                    </td>
                                    <td>
                                        <div class="btn--container justify-content-center">
                                            <a class="btn action-btn btn--primary btn-outline-primary" href="">
                                                <i class="tio-edit"></i>
                                            </a>
                                            <a class="btn action-btn btn--danger btn-outline-danger" href="javascript:" title="">
                                                <i class="tio-delete-outlined"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="page-area pt-2 pb-2">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-2 flex-sm-nowrap flex-wrap">
                                <select name="" class="custom-select global-bg-box py-0 w-auto h-26px fs-12" id="">
                                    <option value="all">20 Items</option>
                                    <option value="">10</option>
                                    <option value="">8</option>
                                    <option value="">15</option>
                                    <option value="">19</option>
                                </select>
                                <p class="fs-12 m-0 text-gray1">
                                    Showing 1 To 20 Of 100 Records
                                </p>
                            </div>
                            <ul class="pagination m-0">
                                <li class="page-item disabled" aria-disabled="true" aria-label="« Previous">
                                    <i class="tio-chevron-left"></i>
                                </li>
                                <li class="page-item active" aria-current="page"><span class="page-link">1</span></li>
                                <li class="page-item"><a class="page-link" href="http://localhost/StackFood-Admin/admin/food/list?page=2">2</a></li>
                                <li class="page-item"><a class="page-link" href="http://localhost/StackFood-Admin/admin/food/list?page=3">3</a></li>
                                <li class="page-item">
                                    <a class="page-link" href="#0" rel="next" aria-label="Next »">
                                        <i class="tio-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>





@endsection
