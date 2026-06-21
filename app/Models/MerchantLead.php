<?php

namespace App\Models;

use App\Traits\MasksSensitiveAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MerchantLead extends Model
{
    use HasFactory, MasksSensitiveAttributes;

    protected $guarded = ['id'];

    protected $casts = [
        'status' => 'integer',
        'seen' => 'boolean',
    ];

    // 历史命名：H5 入驻表单只收「联系邮箱」，落库在 phone 列（未收电话）。
    // 提供 email 只读别名，代码/视图统一用 $lead->email 读取，避免被列名 phone 误导。
    public function getEmailAttribute()
    {
        return $this->phone;
    }

    public function statusLabel()
    {
        return [
            0 => '待跟进',
            1 => '跟进中',
            2 => '已完成',
            3 => '无效',
        ][$this->status] ?? '待跟进';
    }
}
