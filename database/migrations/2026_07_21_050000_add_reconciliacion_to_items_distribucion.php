<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items_distribucion', function (Blueprint $table) {
            // Conciliación FIFO: un ítem se marca reconocido a medida que su costo se
            // reclasifica (14→61). Pendiente = costo − monto_reconocido.
            $table->boolean('reconocido')->default(false)->after('costo');
            $table->decimal('monto_reconocido', 18, 2)->default(0)->after('reconocido');
            $table->timestamp('reconocido_at')->nullable()->after('monto_reconocido');
            $table->unsignedBigInteger('distribucion_id')->nullable()->after('reconocido_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('items_distribucion', function (Blueprint $table) {
            $table->dropColumn(['reconocido', 'monto_reconocido', 'reconocido_at', 'distribucion_id']);
        });
    }
};
