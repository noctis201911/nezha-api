@extends('layouts.admin.app')
@section('title', translate('安全审计日志'))
@section('content')
    <div class="content container-fluid">
        <div class="page-header">
            <h1 class="page-header-title"><i class="tio-security-on"></i> {{ translate('安全审计日志') }}
                <span class="badge badge-soft-secondary ml-2">{{ translate('只读') }}</span>
            </h1>
        </div>

        <div class="alert alert-info" role="alert">
            <i class="tio-info"></i>
            {{ translate('记录后台危险操作(改风控阈值 / 角色权限增删改 / 员工增删改)的不可篡改留痕。本页仅供查看, 不提供编辑或删除入口(append-only)。密钥与密码明文不入此表。') }}
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row align-items-end gx-2">
                    <div class="col-sm-4 mb-2">
                        <label class="input-label">{{ translate('操作类型') }}</label>
                        <select name="action" class="form-control">
                            <option value="all" {{ $action == 'all' ? 'selected' : '' }}>{{ translate('全部') }}</option>
                            @foreach ($actions as $a)
                                <option value="{{ $a }}" {{ $action == $a ? 'selected' : '' }}>{{ $a }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-3 mb-2">
                        <label class="input-label">{{ translate('起始日期') }}</label>
                        <input type="date" name="from" value="{{ $from }}" class="form-control">
                    </div>
                    <div class="col-sm-3 mb-2">
                        <label class="input-label">{{ translate('结束日期') }}</label>
                        <input type="date" name="to" value="{{ $to }}" class="form-control">
                    </div>
                    <div class="col-sm-2 mb-2">
                        <button type="submit" class="btn btn-primary btn-block">{{ translate('筛选') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="card-header-title">{{ translate('共') }} {{ $logs->total() }} {{ translate('条') }}</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-borderless table-thead-bordered table-align-middle card-table">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('ID') }}</th>
                            <th>{{ translate('时间') }}</th>
                            <th>{{ translate('操作人') }}</th>
                            <th>{{ translate('操作类型') }}</th>
                            <th>{{ translate('对象') }}</th>
                            <th>{{ translate('变更前后') }}</th>
                            <th>{{ translate('IP') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $row)
                            <tr>
                                <td>{{ $row->id }}</td>
                                <td><small>{{ $row->created_at }}</small></td>
                                <td>
                                    {{ $row->actor_name ?? translate('未知') }}
                                    @if ($row->actor_admin_id)
                                        <br><small class="text-muted">#{{ $row->actor_admin_id }}</small>
                                    @endif
                                </td>
                                <td><span class="badge badge-soft-dark">{{ $row->action }}</span></td>
                                <td>
                                    <small>{{ $row->target_type ?? '-' }}</small>
                                    @if ($row->target_id)
                                        <br><small class="text-muted">#{{ $row->target_id }}</small>
                                    @endif
                                </td>
                                <td style="max-width:380px;">
                                    @if ($row->before || $row->after)
                                        <details>
                                            <summary class="text-primary" style="cursor:pointer;">{{ translate('展开') }}</summary>
                                            @if ($row->before)
                                                <div class="mt-1"><small class="text-muted">{{ translate('变更前') }}:</small>
                                                    <pre class="mb-1" style="white-space:pre-wrap;word-break:break-all;font-size:11px;">{{ json_encode($row->before, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                                                </div>
                                            @endif
                                            @if ($row->after)
                                                <div><small class="text-muted">{{ translate('变更后') }}:</small>
                                                    <pre class="mb-0" style="white-space:pre-wrap;word-break:break-all;font-size:11px;">{{ json_encode($row->after, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                                                </div>
                                            @endif
                                        </details>
                                    @else
                                        <small class="text-muted">-</small>
                                    @endif
                                </td>
                                <td><small class="text-monospace">{{ $row->ip ?? '-' }}</small></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">{{ translate('暂无审计记录') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($logs->hasPages())
                <div class="card-footer">
                    <div class="d-flex justify-content-center">
                        {!! $logs->links() !!}
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
