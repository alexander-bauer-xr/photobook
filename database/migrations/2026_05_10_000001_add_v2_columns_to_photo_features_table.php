<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('photo_features', function (Blueprint $t) {
            $t->json('suggested_crop')->nullable()->after('saliency');    // {align:{x,y}, zoom}
            $t->json('dominant_colors')->nullable()->after('suggested_crop');
            $t->string('analysis_version', 10)->default('v1')->after('dominant_colors');
            $t->timestamp('analyzed_at')->nullable()->after('analysis_version');
        });
    }

    public function down(): void {
        Schema::table('photo_features', function (Blueprint $t) {
            $t->dropColumn(['suggested_crop', 'dominant_colors', 'analysis_version', 'analyzed_at']);
        });
    }
};
