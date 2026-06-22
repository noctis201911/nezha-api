@extends('layouts.admin.app')
@section('title', translate('商家 KYC 资料'))
@section('content')
    <div class="content container-fluid">
        <div class="page-header">
            <h1 class="page-header-title"><i class="tio-user-shield"></i> {{ translate('商家 KYC 资料') }}</h1>
        </div>

        <div class="alert alert-info" role="alert">
            <i class="tio-info"></i>
            {{ translate('轻量 KYC(方案B): 运营当面/视频核验商家法人身份后,在此录入【核验结论】(默认不上传证件扫描件,降低 PII 负债)。法人姓名、证件号、收款账户等为敏感信息,已加密存储。') }}
        </div>

        <div class="card">
            <div class="card-header">
                <form action="{{ route('admin.nezha-kyc.index') }}" method="get" class="row g-2 align-items-center w-100">
                    <div class="col-sm-6 col-md-4">
                        <input type="search" name="search" value="{{ $search }}" class="form-control"
                            placeholder="{{ translate('搜索商家名/邮箱/电话') }}">
                    </div>
                    <div class="col-sm-3 col-md-2">
                        <button type="submit" class="btn btn-primary">{{ translate('搜索') }}</button>
                    </div>
                </form>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-borderless table-thead-bordered table-align-middle">
                        <thead class="thead-light">
                            <tr>
                                <th>{{ translate('ID') }}</th>
                                <th>{{ translate('商家') }}</th>
                                <th>{{ translate('联系方式') }}</th>
                                <th>{{ translate('KYC 状态') }}</th>
                                <th>{{ translate('制裁筛查') }}</th>
                                <th>{{ translate('操作') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($restaurants as $r)
                                @php($p = $profiles[$r->id] ?? null)
                                @php($ks = $p->kyc_status ?? 'none')
                                <tr>
                                    <td>{{ $r->id }}</td>
                                    <td>{{ $r->name }}</td>
                                    <td><small class="text-muted">{{ $r->email }}<br>{{ $r->phone }}</small></td>
                                    <td>
                                        @if ($ks === 'approved')
                                            <span class="badge badge-soft-success">{{ translate('已通过') }}</span>
                                        @elseif ($ks === 'pending')
                                            <span class="badge badge-soft-warning">{{ translate('待审核') }}</span>
                                        @elseif ($ks === 'rejected')
                                            <span class="badge badge-soft-danger">{{ translate('已拒绝') }}</span>
                                        @else
                                            <span class="badge badge-soft-secondary">{{ translate('未建档') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @php($ss = $p->screen_status ?? 'not_run')
                                        @if ($ss === 'hit')
                                            <span class="badge badge-soft-danger">{{ translate('命中') }}</span>
                                        @elseif ($ss === 'possible')
                                            <span class="badge badge-soft-warning">{{ translate('疑似·转人工') }}</span>
                                        @elseif ($ss === 'clear')
                                            <span class="badge badge-soft-success">{{ translate('已筛·无命中') }}</span>
                                        @else
                                            <span class="badge badge-soft-secondary">{{ translate('未筛查') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.nezha-kyc.edit', $r->id) }}"
                                            class="btn btn-sm btn-outline-primary">
                                            {{ $ks === 'none' ? translate('录入') : translate('查看/审核') }}
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">{{ translate('暂无商家') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">
                    {!! $restaurants->links() !!}
                </div>
            </div>
        </div>
    </div>
@endsection
