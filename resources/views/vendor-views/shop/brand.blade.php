@extends('layouts.vendor.app')

@section('title', translate('门店形象'))

@section('content')
    <div class="content container-fluid">

        <div class="mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h1 class="page-header-title mb-0" style="font-size:20px;">{{ translate('门店形象') }}</h1>
                <p class="fs-12 text-muted mb-0 mt-1">{{ translate('顾客在 App 里看到的店铺门面，都在这一页管理。') }}</p>
            </div>
            @php($doneCount = ($shop?->logo ? 1 : 0) + ($shop?->cover_photo ? 1 : 0))
            <span class="d-inline-flex align-items-center gap-2 px-3 py-2 rounded"
                  style="background:#FBEAEE;color:#C4193E;font-size:13px;white-space:nowrap;">
                <i class="tio-photo"></i> {{ translate('门面完整度') }} <strong>{{ $doneCount }} / 2</strong>
            </span>
        </div>

        <div class="row g-3">
            <div class="col-lg-8">

                {{-- Logo --}}
                <div class="card mb-3">
                    <div class="card-body d-flex align-items-center flex-wrap" style="gap:16px;">
                        <div class="brand-thumb brand-thumb--square">
                            <img src="{{ $shop?->logo_full_url ?? dynamicAsset('assets/admin/img/image-place-holder.png') }}" alt="logo">
                        </div>
                        <div class="flex-grow-1" style="min-width:180px;">
                            <div class="d-flex align-items-center gap-2">
                                <h3 class="mb-0" style="font-size:16px;">{{ translate('店铺 Logo') }}</h3>
                                @if($shop?->logo)
                                    <span style="font-size:11px;color:#0F6E56;background:#E1F5EE;border-radius:20px;padding:1px 8px;">{{ translate('已上传') }}</span>
                                @else
                                    <span style="font-size:11px;color:#854F0B;background:#FAEEDA;border-radius:20px;padding:1px 8px;">{{ translate('未上传') }}</span>
                                @endif
                            </div>
                            <p class="fs-12 text-muted mb-1 mt-2"><i class="tio-visible"></i> {{ translate('显示在：店铺列表卡、店铺主页顶部') }}</p>
                            <p class="fs-12 text-muted mb-0">{{ translate('推荐 800 × 800（1:1）· JPG / PNG，≤2MB') }}</p>
                        </div>
                        <form action="{{ route('vendor.shop.logo-update') }}" method="post" enctype="multipart/form-data" class="mb-0">
                            @csrf
                            <input type="file" name="logo" accept="image/*" id="brandLogoFile" class="d-none" onchange="this.form.submit()">
                            <label for="brandLogoFile" class="btn btn--primary btn-sm mb-0" style="color:#fff;">{{ $shop?->logo ? translate('更换') : translate('上传') }}</label>
                        </form>
                    </div>
                </div>

                {{-- Cover --}}
                <div class="card mb-3">
                    <div class="card-body d-flex align-items-center flex-wrap" style="gap:16px;">
                        <div class="brand-thumb brand-thumb--wide">
                            <img src="{{ $shop?->cover_photo_full_url ?? dynamicAsset('assets/admin/img/restaurant_cover.jpg') }}" alt="cover">
                        </div>
                        <div class="flex-grow-1" style="min-width:180px;">
                            <div class="d-flex align-items-center gap-2">
                                <h3 class="mb-0" style="font-size:16px;">{{ translate('店铺封面') }}</h3>
                                @if($shop?->cover_photo)
                                    <span style="font-size:11px;color:#0F6E56;background:#E1F5EE;border-radius:20px;padding:1px 8px;">{{ translate('已上传') }}</span>
                                @else
                                    <span style="font-size:11px;color:#854F0B;background:#FAEEDA;border-radius:20px;padding:1px 8px;">{{ translate('未上传') }}</span>
                                @endif
                            </div>
                            <p class="fs-12 text-muted mb-1 mt-2"><i class="tio-visible"></i> {{ translate('显示在：店铺主页顶部大图') }}</p>
                            <p class="fs-12 text-muted mb-0">{{ translate('推荐 1100 × 320（约 3.4:1）· JPG / PNG，≤2MB') }}</p>
                        </div>
                        <form action="{{ route('vendor.shop.cover-update') }}" method="post" enctype="multipart/form-data" class="mb-0">
                            @csrf
                            <input type="file" name="cover_photo" accept="image/*" id="brandCoverFile" class="d-none" onchange="this.form.submit()">
                            <label for="brandCoverFile" class="btn btn--primary btn-sm mb-0" style="color:#fff;">{{ $shop?->cover_photo ? translate('更换') : translate('上传') }}</label>
                        </form>
                    </div>
                </div>

                {{-- 分享图 (展示 + 去设置) --}}
                <div class="card mb-3">
                    <div class="card-body d-flex align-items-center flex-wrap" style="gap:16px;">
                        <div class="brand-thumb brand-thumb--square" style="background:#f8f9fa;">
                            @if($shop?->meta_image)
                                <img src="{{ $shop?->meta_image_full_url }}" alt="share">
                            @else
                                <span class="d-flex align-items-center justify-content-center w-100 h-100 text-muted"><i class="tio-photo" style="font-size:22px;"></i></span>
                            @endif
                        </div>
                        <div class="flex-grow-1" style="min-width:180px;">
                            <div class="d-flex align-items-center gap-2">
                                <h3 class="mb-0" style="font-size:16px;">{{ translate('分享图') }}</h3>
                                @if($shop?->meta_image)
                                    <span style="font-size:11px;color:#0F6E56;background:#E1F5EE;border-radius:20px;padding:1px 8px;">{{ translate('已上传') }}</span>
                                @else
                                    <span style="font-size:11px;color:#854F0B;background:#FAEEDA;border-radius:20px;padding:1px 8px;">{{ translate('未上传') }}</span>
                                @endif
                            </div>
                            <p class="fs-12 text-muted mb-1 mt-2"><i class="tio-visible"></i> {{ translate('显示在：转发 / 分享店铺链接时的卡片') }}</p>
                            <p class="fs-12 text-muted mb-0">{{ translate('推荐 1200 × 630 · JPG / PNG，≤2MB · 不传则用封面兜底') }}</p>
                        </div>
                        <form action="{{ route('vendor.shop.meta-image-update') }}" method="post" enctype="multipart/form-data" class="mb-0">
                            @csrf
                            <input type="file" name="meta_image" accept="image/*" id="brandMetaFile" class="d-none" onchange="this.form.submit()">
                            <label for="brandMetaFile" class="btn btn--primary btn-sm mb-0" style="color:#fff;">{{ $shop?->meta_image ? translate('更换') : translate('上传') }}</label>
                        </form>
                    </div>
                </div>

            </div>

            {{-- 顾客端预览 --}}
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <p class="fs-12 text-muted mb-2"><i class="tio-android-phone"></i> {{ translate('顾客端预览') }}</p>
                        <div class="brand-phone">
                            <div class="brand-phone__cover" style="background-image:url('{{ $shop?->cover_photo_full_url }}');">
                                <span class="brand-phone__logo">
                                    <img src="{{ $shop?->logo_full_url ?? dynamicAsset('assets/admin/img/image-place-holder.png') }}" alt="logo">
                                </span>
                            </div>
                            <div class="brand-phone__meta">
                                <div class="brand-phone__name">{{ $shop?->name }}</div>
                                <div class="brand-phone__sub">{{ translate('你的门面：封面做背景、Logo 做头像') }}</div>
                            </div>
                        </div>
                        <p class="fs-11 text-muted text-center mt-2 mb-0">{{ translate('换图后刷新，即见顾客眼里的样子') }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-2 px-3 py-2 rounded" style="background:#F1EFE8;color:#5F5E5A;font-size:12px;">
            <i class="tio-info-outined"></i> {{ translate('菜品图、广告图不在这一页 —— 它们跟着各自的菜品 / 广告走，在对应页面上传。这里只管整店门面。') }}
        </div>

    </div>

    <style>
        .brand-thumb{flex-shrink:0;border-radius:10px;overflow:hidden;border:1px solid #e7eaf3;background:#f8f9fa;}
        .brand-thumb img{width:100%;height:100%;object-fit:cover;display:block;}
        .brand-thumb--square{width:62px;height:62px;}
        .brand-thumb--wide{width:104px;height:34px;}
        .brand-phone{width:210px;margin:0 auto;border:1px solid #e7eaf3;border-radius:16px;overflow:hidden;background:#fff;box-shadow:0 2px 10px rgba(20,22,40,.06);}
        .brand-phone__cover{height:82px;background-color:#E8C9A0;background-size:cover;background-position:center;position:relative;}
        .brand-phone__logo{position:absolute;left:12px;bottom:-18px;width:48px;height:48px;border-radius:13px;overflow:hidden;border:2px solid #fff;background:#fff;}
        .brand-phone__logo img{width:100%;height:100%;object-fit:cover;display:block;}
        .brand-phone__meta{padding:25px 12px 13px;}
        .brand-phone__name{font-size:14px;font-weight:600;color:#1F2329;}
        .brand-phone__sub{font-size:11px;color:#8A9099;margin-top:3px;}
    </style>
@endsection
