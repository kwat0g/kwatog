<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void { DB::table('settings')->insertOrIgnore(['key'=>'quality.rollout.fixed_sample_size','value'=>json_encode(3),'group'=>'quality','label'=>'Baseline Quality Plan Fixed Sample Size','description'=>'Sample size used by the baseline quality-plan rollout command.','created_at'=>now(),'updated_at'=>now()]); }
    public function down(): void { DB::table('settings')->where('key','quality.rollout.fixed_sample_size')->delete(); }
};
