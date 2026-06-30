<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒 阶段B: 超管职员编号。admins.employee_code (前缀-序号, 如 CS-001) + admin_roles.code_prefix (岗位前缀)。
return new class extends Migration {
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            if (!Schema::hasColumn('admins', 'employee_code')) {
                $table->string('employee_code', 30)->nullable()->unique()->after('id');
            }
        });
        Schema::table('admin_roles', function (Blueprint $table) {
            if (!Schema::hasColumn('admin_roles', 'code_prefix')) {
                $table->string('code_prefix', 10)->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            if (Schema::hasColumn('admins', 'employee_code')) {
                $table->dropUnique(['employee_code']);
                $table->dropColumn('employee_code');
            }
        });
        Schema::table('admin_roles', function (Blueprint $table) {
            if (Schema::hasColumn('admin_roles', 'code_prefix')) {
                $table->dropColumn('code_prefix');
            }
        });
    }
};