<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('photobook_projects', function (Blueprint $t) {
            $t->id();
            $t->string('folder_hash', 40)->unique();  // sha1(nextcloud folder path)
            $t->string('folder')->nullable();          // human-readable Nextcloud path
            $t->json('pages_json')->nullable();        // full pages state (RenderSpec array)
            $t->json('cover_json')->nullable();        // cover metadata (title, image, etc.)
            $t->string('analysis_version', 10)->default('v2');
            $t->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('photobook_projects');
    }
};
