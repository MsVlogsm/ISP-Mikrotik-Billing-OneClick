<?php
namespace App\Console\Commands;
use App\Services\DueCallReminderService;
use Illuminate\Console\Command;
class ProcessDueCallReminders extends Command {
 protected $signature='billing:due-call-reminders {--force : Process even if auto-call is disabled}';
 protected $description='Submit due-customer voice-call reminders to the configured voice API';
 public function handle(DueCallReminderService $service): int {$r=$service->runBatch((bool)$this->option('force'));$this->info($r['message']);return str_contains(strtolower($r['message']),'invalid')?self::FAILURE:self::SUCCESS;}
}
