<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The products sold on Blinkit and what their labels print: Blinkit's own
 * product id (PID) and UPC, the name and MRP as Blinkit lists them, the
 * pack size and how many go in a carton. Dates are given each time
 * labels are printed. Starts with the products on the label sheet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blinkit_products', function (Blueprint $table): void {
            $table->id();
            $table->string('brand_name', 100);
            $table->string('product_name', 255);
            $table->string('upc', 32)->unique();
            $table->string('pid', 32)->nullable();
            $table->decimal('mrp', 10, 2);
            $table->string('uom', 32)->nullable();
            $table->unsignedSmallInteger('units_in_box')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();

        DB::table('blinkit_products')->insert([
            ['brand_name' => 'Rahat Rooh', 'product_name' => 'Rahat rooh Sukoon Pain Relieving Oil 300ml pack of 1', 'upc' => '21650766', 'pid' => '768007', 'mrp' => '562', 'uom' => '300 ml', 'units_in_box' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['brand_name' => 'Rahat Rooh', 'product_name' => 'Rahat rooh Ayurvedic Hair Oil for Headache & Relaxation Oil 300ml pack of 1', 'upc' => '21650720', 'pid' => '768191', 'mrp' => '710', 'uom' => '300 ml', 'units_in_box' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['brand_name' => 'Rahat Rooh', 'product_name' => 'Rahat rooh Ayurved Ratna Cool Hair Oil(Bottle)-300ml', 'upc' => '21650697', 'pid' => '1390557', 'mrp' => '280', 'uom' => '300 ml', 'units_in_box' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('blinkit_products');
    }
};
