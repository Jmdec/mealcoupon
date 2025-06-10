<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
     public function up()
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // coupon_expiry, usage_alert, system, achievement, department_alert
            $table->string('title');
            $table->text('message');
            $table->boolean('read')->default(false);
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->string('department')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['read', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
