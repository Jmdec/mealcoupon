<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('barcode_image_path')->nullable()->after('barcode');
            $table->string('barcode_svg_path')->nullable()->after('barcode_image_path');
            $table->longText('barcode_base64')->nullable()->after('barcode_svg_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn(['barcode_image_path', 'barcode_svg_path', 'barcode_base64']);
        });
    }
};
