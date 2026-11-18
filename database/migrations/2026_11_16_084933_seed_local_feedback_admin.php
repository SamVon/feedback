<?php

use Database\Seeders\LocalAdminSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(LocalAdminSeeder::class)->run();
    }

    public function down(): void
    {
        // Preserve staff identity and its audit history when rolling back application migrations.
    }
};
