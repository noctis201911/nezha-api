<?php

namespace App\Rules;

use App\CentralLogics\NezhaBackofficeEmailBoundary;
use Illuminate\Contracts\Validation\Rule;

final class UniqueBackofficeEmail implements Rule
{
    private ?string $ignoreTable;

    private ?int $ignoreId;

    public function __construct(?string $ignoreTable = null, ?int $ignoreId = null)
    {
        $this->ignoreTable = $ignoreTable;
        $this->ignoreId = $ignoreId;
    }

    public function passes($attribute, $value): bool
    {
        return ! NezhaBackofficeEmailBoundary::conflicts($value, $this->ignoreTable, $this->ignoreId);
    }

    public function message(): string
    {
        return '该邮箱已用于管理员、商家或商家员工账号，请更换邮箱。';
    }
}
