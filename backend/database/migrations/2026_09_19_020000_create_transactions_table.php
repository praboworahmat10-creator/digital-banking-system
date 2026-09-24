<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->unique();
            $table->foreignId('sender_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('recipient_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('type')->default('transfer'); // transfer, topup, withdrawal
            $table->string('description')->nullable();
            $table->string('status')->default('success'); // success, pending, failed
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
