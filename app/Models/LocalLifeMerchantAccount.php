<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Notifications\LocalMerchantResetPassword;

/**
 * 本地生活商户轻管理面「账号」。一账号一店（v1）。
 * 走独立 guard `local_merchant`（session），与主站 users/vendors 完全隔离。
 * 密码：邮箱自助设密（设密前 password 为空，账号存在但不能登录，只能走「设置密码」邮件）。
 */
class LocalLifeMerchantAccount extends Authenticatable
{
    use Notifiable;

    protected $table = 'local_life_merchant_accounts';

    protected $fillable = [
        'merchant_id', 'email', 'password', 'contact_name', 'status', 'last_login_at',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'status'        => 'boolean',
        'last_login_at' => 'datetime',
    ];

    /** 所属商户条目 */
    public function merchant()
    {
        return $this->belongsTo(LocalLifeMerchant::class, 'merchant_id');
    }

    /** 是否已设密（未设密=只能走邮件设密链接，不能用密码登录） */
    public function hasPassword(): bool
    {
        return !empty($this->password);
    }

    /** 自定义密码重置通知：链接指向商户面板 /m/reset，中文文案 */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new LocalMerchantResetPassword($token));
    }
}
