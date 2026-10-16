<?php

namespace App\Livewire;

use App\Models\NetworkEvent;
use App\Models\RouterList;
use App\Models\MainSiteData;
use App\Services\NetworkAlertNotificationService;
use Livewire\Component;

class NetworkEvents extends Component
{
    public string $search = '';
    public string $severity = '';
    public string $status = 'open';
    public string $message = '';
    public bool $whatsapp_enabled = false;
    public bool $telegram_enabled = false;
    public string $whatsapp_url = '';
    public string $whatsapp_token = '';
    public string $whatsapp_to = '';
    public string $telegram_bot_token = '';
    public string $telegram_chat_id = '';
    public string $min_severity = 'warning';

    public function mount(): void
    {
        if (! hasAccess(['Super Admin'], ['mikrotik-setup', 'network-inventory'])) {
            abort(403);
        }
        $settings = MainSiteData::getValue('network_alert_channels', []);
        $this->whatsapp_enabled = (bool) ($settings['whatsapp_enabled'] ?? false);
        $this->telegram_enabled = (bool) ($settings['telegram_enabled'] ?? false);
        $this->whatsapp_url = (string) ($settings['whatsapp_url'] ?? '');
        $this->whatsapp_token = (string) ($settings['whatsapp_token'] ?? '');
        $this->whatsapp_to = (string) ($settings['whatsapp_to'] ?? '');
        $this->telegram_bot_token = (string) ($settings['telegram_bot_token'] ?? '');
        $this->telegram_chat_id = (string) ($settings['telegram_chat_id'] ?? '');
        $this->min_severity = (string) ($settings['min_severity'] ?? 'warning');
    }

    public function saveNotificationSettings(): void
    {
        $this->validate([
            'whatsapp_url' => 'nullable|url|max:500',
            'whatsapp_token' => 'nullable|string|max:1000',
            'whatsapp_to' => 'nullable|string|max:100',
            'telegram_bot_token' => 'nullable|string|max:500',
            'telegram_chat_id' => 'nullable|string|max:100',
            'min_severity' => 'required|in:info,warning,critical,device_down',
        ]);

        MainSiteData::setValue('network_alert_channels', [
            'whatsapp_enabled' => $this->whatsapp_enabled,
            'whatsapp_url' => $this->whatsapp_url,
            'whatsapp_token' => $this->whatsapp_token,
            'whatsapp_to' => $this->whatsapp_to,
            'telegram_enabled' => $this->telegram_enabled,
            'telegram_bot_token' => $this->telegram_bot_token,
            'telegram_chat_id' => $this->telegram_chat_id,
            'min_severity' => $this->min_severity,
        ]);
        $this->message = 'WhatsApp / Telegram alert settings saved.';
    }

    public function testNotification(string $channel): void
    {
        abort_unless(in_array($channel, ['whatsapp', 'telegram'], true), 404);
        $result = app(NetworkAlertNotificationService::class)->test($channel);
        $item = $result[$channel] ?? [];
        $this->message = !empty($item['ok'])
            ? ucfirst($channel).' test alert sent successfully.'
            : ucfirst($channel).' test failed: '.($item['error'] ?? 'Unknown error');
    }

    public function resendTelegram(int $id): void
    {
        $event = NetworkEvent::findOrFail($id);
        $result = app(NetworkAlertNotificationService::class)->resendTelegram($event)['telegram'] ?? [];
        $this->message = ! empty($result['ok'])
            ? 'Telegram alert পুনরায় পাঠানো হয়েছে।'
            : 'Telegram resend ব্যর্থ: '.($result['error'] ?? 'অজানা সমস্যা');
    }

    public function acknowledge(int $id): void
    {
        $event = NetworkEvent::whereKey($id)->where('status', 'open')->firstOrFail();
        $event->update([
            'status' => 'acknowledged',
            'acknowledged_by' => auth()->id(),
            'acknowledged_at' => now(),
        ]);
        $this->message = 'Alert acknowledged.';
    }

    public function resolve(int $id): void
    {
        NetworkEvent::whereKey($id)->whereIn('status', ['open', 'acknowledged'])->firstOrFail()->update(['status' => 'resolved', 'resolved_by' => auth()->id(), 'resolved_at' => now()]);
        $this->message = 'Alert resolved.';
    }

    public function reopen(int $id): void
    {
        NetworkEvent::whereKey($id)->where('status', 'resolved')->firstOrFail()->update(['status' => 'open', 'acknowledged_by' => null, 'acknowledged_at' => null, 'resolved_by' => null, 'resolved_at' => null]);
        $this->message = 'Alert reopened.';
    }

    public function render()
    {
        $query = NetworkEvent::query()->with(['device', 'acknowledgedBy', 'resolvedBy', 'alertDeliveries'])->latest('last_seen_at')->latest('id');
        if ($this->status !== 'all') {
            $query->where('status', $this->status ?: 'open');
        }
        if ($this->severity !== '') {
            $query->where('severity', $this->severity);
        }
        $q = trim($this->search);
        if ($q !== '') {
            $like = '%'.$q.'%';
            $routerIds = RouterList::where('router_name', 'like', $like)->pluck('id')->all();
            $query->where(function ($w) use ($like, $routerIds) {
                $w->where('title', 'like', $like)
                    ->orWhere('message', 'like', $like)
                    ->orWhere('event_type', 'like', $like)
                    ->orWhereHas('device', fn ($d) => $d->where('name', 'like', $like)->orWhere('ip_address', 'like', $like));
                foreach ($routerIds as $routerId) {
                    $w->orWhere('metadata->router_id', $routerId);
                }
            });
        }
        $events = $query->paginate(25);
        $routerIds = $events->getCollection()->filter(fn ($event) => ! $event->device && data_get($event->metadata, 'router_id'))
            ->map(fn ($event) => (int) data_get($event->metadata, 'router_id'))->unique()->values();
        $routers = $routerIds->isEmpty() ? collect() : RouterList::whereIn('id', $routerIds)->get()->keyBy('id');
        $open = NetworkEvent::whereIn('status', ['open', 'acknowledged'])->count();
        $critical = NetworkEvent::whereIn('status', ['open', 'acknowledged'])->where('severity', 'critical')->count();
        $warning = NetworkEvent::whereIn('status', ['open', 'acknowledged'])->where('severity', 'warning')->count();
        $resolvedToday = NetworkEvent::where('status', 'resolved')->whereDate('updated_at', today())->count();

        return view('livewire.network-events', compact('events', 'routers', 'open', 'critical', 'warning', 'resolvedToday'))
            ->layout('layouts.app');
    }
}
