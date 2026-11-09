<?php
namespace App\Services\Olt;

use App\Models\NetworkInventoryDevice;
use Illuminate\Support\Facades\Crypt;
use phpseclib3\Net\SSH2;
use RuntimeException;

class VsolCliProvisioningService
{
    private SSH2 $ssh;
    private array $config;

    public function connect(NetworkInventoryDevice $device): void
    {
        $this->connectReadOnly($device);
        if (!$device->provisioning_enabled) throw new RuntimeException('OLT provisioning is disabled.');
        $profileKey=(string)($this->config['vsol_model_profile'] ?? ''); $profiles=config('olt_vsol.profiles',[]);
        if ($profileKey==='' || !isset($profiles[$profileKey])) throw new RuntimeException('No exact VSOL model command profile selected.');
        $profile=$profiles[$profileKey]; $model=trim((string)$device->model);
        if ($model==='' || !collect($profile['models']??[])->contains(fn($m)=>strcasecmp(trim($m),$model)===0)) throw new RuntimeException('OLT model does not exactly match the selected VSOL command profile.');
    }

    public function connectReadOnly(NetworkInventoryDevice $device): void
    {
        $this->config = $this->config($device);
        $host = (string)($device->ip_address ?: $device->host);
        if ($host === '') throw new RuntimeException('OLT management IP/host is not configured.');
        if (!$device->ssh_enabled) throw new RuntimeException('OLT SSH access is disabled.');
        $username = $this->secret($device, 'username');
        $password = $this->secret($device, 'password');
        if ($username === '' || $password === '') throw new RuntimeException('OLT SSH credentials are not configured.');
        $port = (int)($device->ssh_port ?: 22);
        $this->ssh = new SSH2($host, $port, max(3, (int)($device->cli_timeout ?: 10)));
        $this->ssh->setTimeout(max(5, (int) ($device->cli_timeout ?: 12)));
        if (!$this->ssh->login($username, $password)) throw new RuntimeException('VSOL SSH login failed.');
    }

    public function discoverVersion(): string
    {
        return $this->runReadOnly('show version');
    }

    /** Enter privileged EXEC only; never enters configuration mode or writes device configuration. */
    public function enterPrivilegedReadOnly(NetworkInventoryDevice $device): void
    {
        $promptName = trim((string) $device->name) ?: 'BT-EPON-OLT';
        $privilegedPrompt = '/'.preg_quote($promptName, '/').'#[[:space:]]*$/D';
        $passwordOrPrivilegedPrompt = '/(?:Password:[[:space:]]*|'.preg_quote($promptName, '/').'#[[:space:]]*)$/D';

        $this->ssh->write("enable\n");
        $response = trim((string) $this->ssh->read($passwordOrPrivilegedPrompt));

        if (preg_match('/Password:[[:space:]]*$/iD', $response)) {
            $enablePassword = $this->secret($device, 'enable_password');
            if ($enablePassword === '') {
                throw new RuntimeException('OLT CLI requires a separate enable password. Save it under Admin Panel → OLT Management → Edit OLT → CLI Enable Password.');
            }

            $this->ssh->write($enablePassword."\n");
            $response = trim((string) $this->ssh->read($passwordOrPrivilegedPrompt));
            if (preg_match('/Password:[[:space:]]*$/iD', $response)) {
                throw new RuntimeException('OLT rejected the CLI enable password. No configuration command was sent.');
            }
        }

        if (!preg_match($privilegedPrompt, $response)) {
            throw new RuntimeException('OLT did not confirm the privileged CLI prompt. No configuration command was sent.');
        }
    }

    /** This command is available in the live-verified user and privileged EXEC modes. */
    public function discoverReadOnlyCommands(): string
    {
        return $this->runReadOnly('list');
    }

    /** Execute the vendor-documented read-only ONU OPM show command in config CLI context. */
    public function readOnlyOpmDiagnostics(NetworkInventoryDevice $device): array
    {
        $this->connectReadOnly($device);
        $this->enterPrivilegedReadOnly($device);

        // Only changes this SSH session's terminal pagination preference.
        $this->ssh->write("terminal length 0\n");
        $this->ssh->read('/(?:#|>)\s*$/D');

        // VSOL's CLI manual places this show-only OPM command under config CLI context.
        // Entering the context does not alter an OLT configuration value.
        $context = $this->runReadOnly('configure terminal');
        if (preg_match('/(?:Unknown command|Command incomplete|There is no matched command)/i', $context)) {
            throw new RuntimeException('OLT did not accept the CLI context needed for the documented read-only OPM show command.');
        }

        $output = $this->runReadOnly('show onu opm-diag all');
        if (preg_match('/(?:Unknown command|Command incomplete|There is no matched command)/i', $output)) {
            throw new RuntimeException('OLT rejected show onu opm-diag all in config CLI context.');
        }
        $lines = array_values(array_filter(preg_split('/\\R/', $output) ?: [], fn ($line) => trim($line) !== ''));
        $onuRows = array_values(array_filter($lines, static fn ($line) => preg_match('/EPON0\/\d+:\d+/', $line)));

        return [
            'accepted' => true,
            'line_count' => count($lines),
            'onu_row_count' => count($onuRows),
            'sample' => array_slice($onuRows, 0, 15),
            'truncated' => count($onuRows) > 15 || str_contains($output, '--More--'),
        ];
    }

    /** Test VSOL's PON-scoped and single-ONU optical show commands without changing device configuration. */
    public function readOnlyPonOpmDiagnostics(NetworkInventoryDevice $device, int $pon, int $onuId): array
    {
        if ($pon < 1 || $pon > 4 || $onuId < 1 || $onuId > 256) {
            throw new RuntimeException('PON/ONU diagnostic identity is outside the supported range.');
        }

        $this->connectReadOnly($device);
        $this->enterPrivilegedReadOnly($device);
        $this->ssh->write("terminal length 0\n");
        $this->ssh->read('/(?:#|>)\s*$/D');
        $this->ssh->write("configure terminal\n");
        $context = trim((string) $this->ssh->read('/BT-EPON-OLT\(config\)#\s*$/D'));
        if (!str_ends_with($context, '(config)#')) {
            throw new RuntimeException('OLT did not enter config CLI context.');
        }
        $this->ssh->write("interface epon 0/{$pon}\n");
        $interfacePrompt = trim((string) $this->ssh->read('/(?:[A-Za-z0-9_.()\/-]+[>#]\s*)$/D'));
        if (!str_contains($interfacePrompt, "epon 0/{$pon}")) {
            throw new RuntimeException('OLT did not confirm the requested EPON interface context.');
        }

        $results = [];
        foreach (['show onu opm-diag', "show onu {$onuId} ctc opm_diag"] as $command) {
            $this->ssh->write($command."\n");
            $output = trim((string) $this->ssh->read('/(?:[A-Za-z0-9_.()\/-]+[>#]\s*)$/D'));
            $lines = array_values(array_filter(preg_split('/\\R/', $output) ?: [], fn ($line) => trim($line) !== ''));
            $onuRows = array_values(array_filter($lines, static fn ($line) => preg_match('/EPON0\/\d+:\d+/', $line)));
            $results[$command] = [
                'accepted' => !preg_match('/(?:Unknown command|Command incomplete|There is no matched command|Invalid input)/i', $output),
                'line_count' => count($lines),
                'onu_row_count' => count($onuRows),
                'sample' => array_slice($onuRows ?: $lines, 0, 20),
            ];
        }

        return $results;
    }

    /** Fetch the live MAC address table in privileged EXEC only; no configuration mode. */
    public function readOnlyMacTable(NetworkInventoryDevice $device): array
    {
        $this->connectReadOnly($device);
        $this->enterPrivilegedReadOnly($device);

        // This is a VTY terminal display preference, not a persistent OLT config change.
        $this->ssh->write("terminal length 0\n");
        $paging = trim((string) $this->ssh->read('/(?:#|>)\s*$/D'));
        if (preg_match('/(?:Unknown command|Command incomplete|There is no matched command)/i', $paging)) {
            throw new RuntimeException('OLT did not accept the terminal paging command; MAC table read was not attempted.');
        }

        $this->ssh->write("show mac address-table\n");
        $output = trim((string) $this->ssh->read('/(?:#|>)\s*$/D'));
        if ($output === '' || preg_match('/(?:Unknown command|Command incomplete|There is no matched command)/i', $output)) {
            throw new RuntimeException('OLT rejected the advertised read-only MAC address-table command.');
        }

        $lines = array_values(array_filter(preg_split('/\\R/', $output) ?: [], fn ($line) => trim($line) !== ''));
        $macRows = [];
        foreach ($lines as $line) {
            if (!preg_match('/^\s*(\d+)\s+((?:[0-9a-f]{4}:){2}[0-9a-f]{4}|(?:[0-9a-f]{2}[:-]){5}[0-9a-f]{2})\s+(Dynamic|Static)\s+(EPON0\/(\d+):(\d+)|\S+)/i', $line, $m)) continue;
            $hex = preg_replace('/[^0-9a-f]/i', '', $m[2]) ?: '';
            if (strlen($hex) !== 12) continue;
            $mac = implode(':', str_split(strtolower($hex), 2));
            $pon = isset($m[4]) && preg_match('/^EPON0\/(\d+):(\d+)$/i', $m[4], $portMatch) ? $portMatch[1] : null;
            $onu = isset($portMatch[2]) ? $portMatch[2] : null;
            $macRows[] = [
                'vlan' => (int) $m[1],
                'mac' => $mac,
                'type' => strtolower($m[3]),
                'port' => $m[4],
                'pon_port' => $pon,
                'onu_id' => $onu,
                'dynamic_onu_client' => strtolower($m[3]) === 'dynamic' && $pon !== null && $onu !== null,
            ];
        }
        $onuMacRows = array_values(array_filter($macRows, static fn ($row) => $row['dynamic_onu_client']));

        return [
            'accepted' => true,
            'line_count' => count($lines),
            'mac_row_count' => count($macRows),
            'dynamic_onu_client_count' => count($onuMacRows),
            'dynamic_onu_client_macs' => $onuMacRows,
            'sample' => array_slice($lines, 0, 30),
            'truncated' => count($lines) > 30 || str_contains($output, '--More--'),
        ];
    }

    private function runReadOnly(string $command): string
    {
        $allowed = [
            'show version', 'show system', 'list', 'show memory', 'show ip rip', 'show ip rip status',
            'terminal length 0', 'show mac address-table', 'show onu ?', 'show onu opm-diag ?', 'show onu opm-diag all', 'configure terminal',
        ];
        if (preg_match('/[\r\n;$]/', $command) || !in_array($command, $allowed, true)) {
            throw new RuntimeException('Unsafe read-only discovery command rejected.');
        }
        $this->ssh->write($command."\n");
        return trim((string)$this->ssh->read('/(?:#|>)\s*$/D'));
    }

    public function authorizeByMac(string $pon, string $mac): string
    {
        $this->validatePon($pon); $this->validateMac($mac);
        return $this->run($this->command('authorize_mac', ['pon'=>$pon,'mac'=>$mac]));
    }

    public function removeByMac(string $pon, string $mac): string
    {
        $this->validatePon($pon); $this->validateMac($mac);
        return $this->run($this->command('remove_mac', ['pon'=>$pon,'mac'=>$mac]));
    }

    public function configurePppoe(string $pon, string $onu, string $username, string $password, string $server = ''): string
    {
        $this->validatePon($pon); $this->validateOnu($onu);
        if ($username === '' || strlen($username) > 128 || strlen($password) > 128 || strlen($server) > 128) throw new RuntimeException('Invalid PPPoE credentials.');
        $commands = $this->commands('pppoe', compact('pon','onu','username','password','server'));
        return $this->runMany($commands);
    }

    public function configureStaticIp(string $pon, string $onu, string $ip, string $netmask, string $gateway, string $dns1 = '', string $dns2 = ''): string
    {
        $this->validatePon($pon); $this->validateOnu($onu);
        foreach ([$ip,$gateway] as $value) if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new RuntimeException('Invalid IPv4 address.');
        if (!filter_var($netmask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new RuntimeException('Invalid IPv4 netmask.');
        foreach ([$dns1, $dns2] as $dns) if ($dns !== '' && !filter_var($dns, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new RuntimeException('Invalid DNS IPv4 address.');
        return $this->runMany($this->commands('static_ip', compact('pon','onu','ip','netmask','gateway','dns1','dns2')));
    }

    /** Restart exactly one already-registered ONU using the selected model profile. */
    public function rebootOnu(string $pon, string $onu): string
    {
        $this->validatePon($pon);
        $this->validateOnu($onu);
        $profileKey = (string) ($this->config['vsol_model_profile'] ?? '');
        $profile = config('olt_vsol.profiles.'.$profileKey, []);
        $allowedFirmware = (array) ($profile['firmware'] ?? []);
        if ($profileKey !== 'v1601e04_dp_v1_0_2r' || ! in_array(strtoupper(trim((string) ($this->config['_validated_firmware'] ?? ''))), $allowedFirmware, true)) {
            throw new RuntimeException('ONU reboot requires the exact V1601E04-DP V1.0.2R CLI profile.');
        }
        $output = trim($this->runMany($this->commands('reboot_onu', compact('pon', 'onu'))));
        if ($output === '') {
            throw new RuntimeException('OLT closed or returned no CLI response after the reset request. Command acceptance cannot be confirmed.');
        }
        if (preg_match('/(?:unknown command|incomplete command|ambiguous command|command failed|no such onu|invalid (?:onu|command)|%\s*error)/i', $output)) {
            throw new RuntimeException('OLT rejected the ONU reset command: '.mb_substr($output, 0, 500));
        }
        return $output;
    }

    public function disableOnu(string $pon, string $onu): string
    {
        $this->validatePon($pon); $this->validateOnu($onu);
        return $this->run($this->command('disable_onu', compact('pon','onu')));
    }

    public function enableOnu(string $pon, string $onu): string
    {
        $this->validatePon($pon); $this->validateOnu($onu);
        return $this->run($this->command('enable_onu', compact('pon','onu')));
    }

    public function save(): string
    {
        return $this->run((string)($this->config['commands']['save'] ?? 'write'));
    }

    private function commands(string $type, array $vars): array
    {
        $configured = data_get($this->config, 'commands.'.$type);
        if (is_array($configured) && $configured) return array_map(fn($c)=>(string)$this->render($c,$vars), $configured);
        if ($type === 'pppoe') return [
            'configure terminal','interface epon '.$vars['pon'],
            'onu '.$vars['onu'].' pri wan_conn add route internet nat enable',
            'onu '.$vars['onu'].' pri wan_conn index 1 pppoe proxy disable user '.$this->quote($vars['username']).' pwd '.$this->quote($vars['password']).' server '.$this->quote($vars['server'] ?? '').' mode auto',
            'onu '.$vars['onu'].' pri wan_conn commit','exit','exit',
        ];
        if ($type === 'static_ip') return [
            'configure terminal','interface epon '.$vars['pon'],
            'onu '.$vars['onu'].' pri wan_conn add route internet nat enable',
            'onu '.$vars['onu'].' pri wan_conn index 1 static ip '.$vars['ip'].' mask '.$vars['netmask'].' gw '.$vars['gateway'].' dns master '.$vars['dns1'].' slave '.$vars['dns2'],
            'onu '.$vars['onu'].' pri wan_conn commit','exit','exit',
        ];
        throw new RuntimeException('No safe VSOL command template configured for '.$type.'. Configure adapter_config.commands.'.$type.' for this exact model.');
    }

    private function command(string $type, array $vars): string
    {
        $configured = data_get($this->config, 'commands.'.$type);
        if (is_array($configured) && $configured) {
            return implode("\n", array_map(fn ($line) => (string) $this->render((string) $line, $vars), $configured));
        }
        if (!$configured) {
            if ($type === 'authorize_mac') return "configure terminal\ninterface epon {$vars['pon']}\nonu-auth mode mac\nonu mac-auth add {$vars['mac']}\nexit\nexit";
            if ($type === 'remove_mac') return "configure terminal\ninterface epon {$vars['pon']}\nonu mac-auth del {$vars['mac']}\nexit\nexit";
            if ($type === 'disable_onu') return "configure terminal\ninterface epon {$vars['pon']}\nonu {$vars['onu']} disable\nexit\nexit";
            if ($type === 'enable_onu') return "configure terminal\ninterface epon {$vars['pon']}\nonu {$vars['onu']} enable\nexit\nexit";
        }
        throw new RuntimeException('No safe VSOL command template configured for '.$type.'.');
    }

    private function runMany(array $commands): string { return $this->run(implode("\n", $commands)); }
    private function run(string $commands): string
    {
        $this->ssh->write($commands."\n");
        $out = $this->ssh->read('/(?:#|>)\s*$/m');
        return trim((string)$out);
    }
    private function render(string $template, array $vars): string
    {
        foreach ($vars as $key=>$value) $template = str_replace('{{'.$key.'}}', $this->quote((string)$value), $template);
        return $template;
    }
    private function quote(string $value): string
    {
        if (preg_match('/[\r\n;`$]/', $value)) throw new RuntimeException('Unsafe command parameter rejected.');
        return $value;
    }
    private function validatePon(string $pon): void { if (!preg_match('/^\d+\/\d+$/',$pon)) throw new RuntimeException('PON must use slot/port format.'); }
    private function validateOnu(string $onu): void { if (!preg_match('/^\d+$/',$onu)) throw new RuntimeException('ONU ID must be numeric.'); }
    private function validateMac(string $mac): void { if (!preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/i',$mac)) throw new RuntimeException('Invalid ONU MAC address.'); }
    private function secret(NetworkInventoryDevice $device, string $field): string
    {
        $raw = $device->getRawOriginal($field); if (!$raw) return '';
        try { return (string)Crypt::decryptString($raw); } catch (\Throwable) { return (string)$raw; }
    }
    private function config(NetworkInventoryDevice $device): array
    {
        $raw=$device->adapter_config; if (is_array($raw)) return $raw;
        $decoded=json_decode((string)$raw,true); return is_array($decoded)?$decoded:[];
    }
}
