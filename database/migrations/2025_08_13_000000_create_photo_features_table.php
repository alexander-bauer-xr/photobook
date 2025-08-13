<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('photo_features', function (Blueprint $t) {
            $t->id();
            $t->string('path')->unique();             // Nextcloud relative path
            $t->string('phash', 32)->nullable();      // 16-byte hex or 64-bit base16
            $t->float('sharpness')->nullable();       // Laplacian variance (log-scaled in scoring)
            $t->json('faces')->nullable();            // [{cx,cy,w,h,score}]
            $t->float('aesthetic')->nullable();       // 0..10
            $t->json('saliency')->nullable();         // {cx, cy, bbox:[x,y,w,h]}
            $t->float('horizon_deg')->nullable();     // -5..+5
            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('photo_features');
    }
};
