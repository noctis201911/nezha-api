<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

/**
 * 哪吒商家「对账中心」导出(预存佣金 / 广告 账户对账单).
 * FromView: 复用 blade 表格, Excel::download 同一实例即可出 .xlsx 或 .csv。
 */
class NezhaReconciliationExport implements FromView, ShouldAutoSize
{
    use Exportable;

    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function view(): View
    {
        return view('file-exports.nezha-reconciliation', ['data' => $this->data]);
    }
}
