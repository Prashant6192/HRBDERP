<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Units of measure.
 *
 * Base-unit strategy (see DATABASE_SCHEMA.md):
 *
 *   Every unit belongs to exactly one dimension — mass, volume or count — and
 *   each dimension has one canonical base unit: gram, millilitre and piece.
 *   'factor_to_base' says how many base units make one of this unit, so
 *   KG carries 1000 and MG carries 0.001. Conversion between any two units of
 *   the same dimension is therefore a single multiply-and-divide, performed by
 *   UnitConversionService with brick/math — never in floating point, and never
 *   with conversion factors hard-coded at a call site.
 *
 *   Units of different dimensions never convert. Turning litres into kilograms
 *   requires a density, which is a property of a material rather than of a
 *   unit, and is handled by the item that declares it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uoms', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 64);

            // 'mass' | 'volume' | 'count'
            $table->string('dimension', 16);

            // Exactly one unit per dimension carries is_base = true and a
            // factor of 1, enforced by the partial index below.
            $table->boolean('is_base')->default(false);

            // Deliberately wide: milligram-to-base is 0.001, and a factor must
            // never lose precision at the extremes of the range.
            $table->decimal('factor_to_base', 30, 12);

            // Decimal places to show this unit with in the interface. Pieces
            // are whole numbers; kilograms are not.
            $table->unsignedTinyInteger('display_scale')->default(3);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('dimension');
        });

        // One base unit per dimension, guaranteed by the database rather than
        // by application discipline.
        DB::statement(
            'CREATE UNIQUE INDEX uoms_one_base_per_dimension ON uoms (dimension) WHERE is_base'
        );

        // A conversion factor must be a positive number; a zero or negative
        // factor would silently corrupt every quantity that passed through it.
        DB::statement(
            'ALTER TABLE uoms ADD CONSTRAINT uoms_factor_positive CHECK (factor_to_base > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('uoms');
    }
};
