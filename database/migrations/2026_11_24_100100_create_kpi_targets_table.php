<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_targets', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->char('period', 7); // YYYY-MM
            $table->unsignedBigInteger('user_id')->nullable(); // null = whole store, otherwise a cashier
            $table->decimal('sales_target', 15, 2)->default(0);
            $table->unsignedInteger('transactions_target')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'period', 'user_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_targets');
    }
};
