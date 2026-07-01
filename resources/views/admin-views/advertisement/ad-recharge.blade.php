@extends('layouts.admin.app')

@section('title','广告余额充值')

@section('advertisement')
active
@endsection

@section('advertisement_recharge')
active
@endsection

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title">
            <span class="page-header-icon">
                <i class="tio-wallet"></i>
            </span>
            <span>广告 · 广告余额充值</span>
        </h1>
        <p class="text-muted mt-2 mb-0">
            商家线下对公付广告费后，在此记入其广告子余额 ad_balance（B2B 预付）。只动 ad_balance、<strong>永不碰经营保证金 deposit_balance</strong>（INV-1）。
        </p>
    </div>

    @if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <div class="row">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <form action="{{ route('admin.advertisement.ad-recharge.store') }}" method="post"
                          onsubmit="return confirm('确认给该商家的广告余额充值？此操作记入 ad_balance 并写审计日志，不可在本页扣减。');">
                        @csrf

                        <div class="alert alert-warning" role="alert">
                            <i class="tio-warning"></i>
                            仅充值（加钱）。冲正 / 扣减需走 CLI，本页不提供扣减入口，避免误操作。
                        </div>

                        <div class="form-group">
                            <label class="input-label">选择商家</label>
                            <select name="vendor_id" class="form-control js-select2-custom" required>
                                <option value="">— 选择商家 —</option>
                                @foreach ($restaurants as $r)
                                    <option value="{{ $r->vendor_id }}">{{ $r->name }}（vendor#{{ $r->vendor_id }} · 当前 ad_balance {{ number_format((float) $r->ad_balance, 0) }} ֏）</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="input-label">充值金额（德拉姆 ֏）</label>
                            <input type="number" min="1" step="1" name="amount" class="form-control" placeholder="10000" required>
                        </div>

                        <div class="form-group">
                            <label class="input-label">备注（可选）</label>
                            <input type="text" maxlength="191" name="note" class="form-control" placeholder="如：2026-07 对公转账流水号 #A1234">
                        </div>

                        <div class="btn--container justify-content-end">
                            <button type="submit" class="btn btn--primary">记一笔充值</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-10">
            <div class="card mt-3">
                <div class="card-header">
                    <h5 class="mb-0">最近广告充值记录（ad_recharge 流水）</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-align-middle">
                            <thead class="thead-light">
                                <tr>
                                    <th>#</th>
                                    <th>商家</th>
                                    <th>金额 ֏</th>
                                    <th>充后余额 ֏</th>
                                    <th>备注</th>
                                    <th>时间</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($recent as $t)
                                <tr>
                                    <td>{{ $t->id }}</td>
                                    <td>{{ $t->restaurant_name ?? ('vendor#'.$t->vendor_id) }}</td>
                                    <td>+{{ number_format((float) $t->amount, 0) }}</td>
                                    <td>{{ number_format((float) $t->balance_after, 0) }}</td>
                                    <td class="text-muted">{{ $t->note }}</td>
                                    <td class="text-muted">{{ $t->created_at }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="6" class="text-center text-muted py-4">暂无广告充值记录</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
