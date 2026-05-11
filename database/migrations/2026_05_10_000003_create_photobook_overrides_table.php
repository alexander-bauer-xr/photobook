<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('photobook_overrides', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')
              ->constrained('photobook_projects')
              ->cascadeOnDelete();
            $t->string('page_id');      // stable page id (e.g. "n-1", "cover")
            $t->json('data_json');      // partial override: template, items, crop etc.
            $t->timestamps();

            $t->unique(['project_id', 'page_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('photobook_overrides');
    }
};
