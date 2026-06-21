<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * 哪吒[IDOR 结构化根治 · 子项A]
 *
 * 顾客归属模型的"安全查询基础方法"。新写顾客端按 id 取/改对象的代码,
 * 一律用 ::forCustomer($userId[, $isGuest]) 圈定归属, 而不是裸 ::find()/where('id')。
 *
 * 关键安全语义: owner id 为 null 直接抛异常——绝不静默退化成"全表无过滤",
 * 这正是 IDOR 的根因(按 id 查不校验归属)。NezhaIdorGuardTest(CI 守卫)会把
 * 顾客控制器里"裸 by-id 查归属模型且无归属过滤"的写法判为 fail, 从结构上防回归。
 *
 * 与 Global Scope 的取舍(见 SECURITY_QA_PLAYBOOK 轴A): 这些模型(Order/Cart/...)
 * 同时被 admin(合法跨用户查)/cron(无登录态) 共用, 全局作用域会把当前顾客 id 当 null
 * 误伤后台与扫单, 故不用 Global Scope, 改用"强制传 owner 的查询方法 + CI 守卫"。
 */
trait OwnedByCustomer
{
    /**
     * 按顾客归属圈定查询。$isGuest 传入时附加 is_guest 过滤(顾客/游客分离)。
     *
     * @param  mixed  $userId   顾客 id 或游客 guest_id(必须非 null)
     * @param  bool|null  $isGuest  null=不过滤; true/false=按 is_guest 过滤
     */
    public function scopeForCustomer(Builder $query, $userId, $isGuest = null): Builder
    {
        if ($userId === null || $userId === '') {
            // 绝不返回未圈定归属的结果集
            throw new \InvalidArgumentException(
                'forCustomer() 需要非空 owner id; 拒绝返回未按归属圈定的数据 ('.static::class.')'
            );
        }

        $query->where($this->getTable().'.user_id', $userId);

        if ($isGuest !== null) {
            $query->where($this->getTable().'.is_guest', $isGuest ? 1 : 0);
        }

        return $query;
    }
}
