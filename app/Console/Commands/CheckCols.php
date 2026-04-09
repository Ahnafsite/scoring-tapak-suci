<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class CheckCols extends Command {
    protected $signature = 'check:cols';
    public function handle() {
        print_r(DB::table('fight_schedules')->pluck('id')->toArray());
    }
}
