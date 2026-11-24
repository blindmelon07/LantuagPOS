<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_features', function (Blueprint $table) {
            // Off by default: the super admin enables them per plan.
            $table->boolean('kpi')->default(false)->after('roles_permission');
            $table->boolean('accounting')->default(false)->after('kpi');
        });
    }

    public function down(): void
    {
        Schema::table('plan_features', function (Blueprint $table) {
            $table->dropColumn(['kpi', 'accounting']);
        });
    }
};
