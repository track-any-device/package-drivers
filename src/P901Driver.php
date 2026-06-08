<?php

declare(strict_types=1);

namespace TrackAnyDevice\Drivers;

use TrackAnyDevice\Drivers\Concerns\QueuesSmsCommands;
use TrackAnyDevice\Drivers\Contracts\DeviceDriverInterface;
use TrackAnyDevice\Drivers\ValueObjects\AddOnCommand;
use TrackAnyDevice\Drivers\ValueObjects\SignalObject;
use TrackAnyDevice\Core\Enums\DeviceCommandStatus;
use TrackAnyDevice\Core\Enums\SignalEventType;
use TrackAnyDevice\Core\Enums\SignalSource;
use TrackAnyDevice\Core\Enums\WorkingMode;
use TrackAnyDevice\Core\Models\Device;
use TrackAnyDevice\Core\Models\DeviceCommand;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

/**
 * Driver for the Cantrack P901 Smart ID Card GPS Walkie-Talkie.
 *
 * Streaming over JT/T 808 TCP (channel = jt808); outgoing config is GSM SMS.
 * Full spec lives in docs/devices/p901.md and the protocol PDF.
 *
 * `parseEventToSignal` accepts:
 *   - ['source' => 'gsm_sms', 'raw' => '...'] for SMS replies
 *   - ['source' => 'stream_jt808', ...JT808 decoded fields...] for stream telemetry
 */
class P901Driver implements DeviceDriverInterface
{
    use QueuesSmsCommands;

    public function getStreamChannel(): string
    {
        return 'jt808';
    }

    public function supportsStream(Device $device): bool
    {
        // Stream is available when the device has reported via JT808 recently.
        return $device->last_signal_at !== null
            && $device->last_signal_at->isAfter(now()->subMinutes(10));
    }

    public function parseEventToSignal(array $rawEvent, Device $device): SignalObject
    {
        $source = $rawEvent['source'] ?? SignalSource::GsmSms->value;

        return match ($source) {
            SignalSource::StreamJt808->value => $this->parseStreamEvent($rawEvent, $device),
            default => $this->parseSmsEvent(['raw' => $rawEvent['raw'] ?? '']),
        };
    }

    public function parseSmsToSignal(string $rawSms, Device $device): SignalObject
    {
        return $this->parseSmsEvent(['raw' => $rawSms]);
    }

    public function requestSignal(string $signalType, Device $device): void
    {
        // Prefer the JT808 stream when the device is online; fall back to a
        // GSM SMS `query` command when it's not.
        if ($this->supportsStream($device)) {
            $this->publishStreamCommand($device, ['msg_id' => 0x8201, 'type' => 'query_location']);

            return;
        }

        $this->queueSms('query_location', [], $device);
    }

    public function setMode(string $mode, Device $device, array $params = []): void
    {
        if ($this->supportsStream($device)) {
            $this->publishStreamCommand($device, [
                'msg_id' => 0x8103,
                'type' => 'set_params',
                'mode' => $mode,
                'interval' => $params['interval'] ?? 30,
            ]);

            return;
        }

        $this->queueSms('set_mode', array_merge(['mode' => $mode], $params), $device);
    }

    public function getMode(Device $device): ?string
    {
        return $device->metadata['working_mode'] ?? null;
    }

    public function onboardingAction(Device $device): void
    {
        $masterNumber = (string) config('sms.master_number', '');
        $host = (string) config('sms.server_host', '');
        $port = (int) config('sms.server_port', 7018);
        $apn = $device->gsmNetwork?->apn ?? 'internet';
        $tzOffset = (int) config('app.timezone_offset', 0);

        if ($masterNumber !== '') {
            $this->queueSms('set_whitelist', ['number' => $masterNumber], $device);
        }

        $this->queueSms('set_server', ['host' => $host, 'port' => $port], $device);
        $this->queueSms('set_apn', ['apn' => $apn], $device);
        $this->queueSms('set_timezone', ['offset' => $tzOffset], $device);
        $this->queueSms('set_mode', ['mode' => 3, 'interval' => '30S'], $device);
        $this->queueSms('check_firmware', [], $device);
    }

    public function addOnCommands(): array
    {
        return [
            new AddOnCommand('set_apn', 'Set APN', ['apn' => ['type' => 'string', 'required' => true]], 'network', true),
            new AddOnCommand('set_server', 'Set Server IP/Port', ['host' => ['type' => 'string', 'required' => true], 'port' => ['type' => 'integer', 'required' => true]], 'network', true),
            new AddOnCommand('set_timezone', 'Set Timezone', ['offset' => ['type' => 'integer', 'required' => true, 'min' => -12, 'max' => 14]], 'network', true),
            new AddOnCommand('set_mode', 'Set Tracking Mode', ['mode' => ['type' => 'select', 'options' => [0, 1, 2, 3], 'required' => true], 'interval' => ['type' => 'string']], 'tracking', false),
            new AddOnCommand('query_location', 'Query Location', [], 'tracking', true),
            new AddOnCommand('set_family_numbers', 'Set Family Numbers', ['number1' => ['type' => 'string', 'required' => true], 'number2' => ['type' => 'string']], 'phone', true),
            new AddOnCommand('set_sos', 'Set SOS Number', ['number' => ['type' => 'string', 'required' => true]], 'alarm', true),
            new AddOnCommand('set_whitelist', 'Set Whitelist', ['number' => ['type' => 'string', 'required' => true]], 'alarm', true),
            new AddOnCommand('set_alarm_mode', 'Set Alarm Delivery', ['mode' => ['type' => 'select', 'options' => [1, 2, 3], 'required' => true]], 'alarm', true),
            new AddOnCommand('low_battery_alarm', 'Low Battery Alarm', ['enabled' => ['type' => 'boolean']], 'alarm', true),
            new AddOnCommand('set_volume', 'Set Volume', ['level' => ['type' => 'integer', 'min' => 0, 'max' => 8, 'required' => true]], 'utility', true),
            new AddOnCommand('check_params', 'Check Parameters', [], 'utility', true),
            new AddOnCommand('check_firmware', 'Check Firmware', [], 'utility', true),
            new AddOnCommand('set_wakeup_alarm', 'Set Wakeup Alarm', ['time' => ['type' => 'time', 'required' => true], 'days' => ['type' => 'string']], 'utility', true),
            new AddOnCommand('set_dnd', 'Do-Not-Disturb', ['start' => ['type' => 'time'], 'end' => ['type' => 'time'], 'days' => ['type' => 'string']], 'utility', true),
            new AddOnCommand('set_sleep', 'Auto Sleep Period', ['start' => ['type' => 'time'], 'end' => ['type' => 'time'], 'days' => ['type' => 'string']], 'utility', true),
            new AddOnCommand('enable_intercom', 'Enable Intercom', ['enabled' => ['type' => 'boolean']], 'intercom', true),
            new AddOnCommand('set_intercom_group', 'Set Intercom Group', ['group_name' => ['type' => 'string', 'required' => true, 'max' => 200]], 'intercom', true),
        ];
    }

    public function addOnCommand(string $commandName, array $parameters, Device $device): void
    {
        $descriptor = $this->matchStreamDescriptor($commandName, $parameters);

        // Commands the JT808 server can encode natively are pushed onto the
        // stream when the device is online. Everything else (and the offline
        // case) falls back to queueing a GSM SMS.
        if ($descriptor !== null && $this->supportsStream($device)) {
            $this->recordStreamCommand($device, $commandName, $descriptor, $parameters);
            $this->publishStreamCommand($device, $descriptor);

            return;
        }

        $this->queueSms($commandName, $parameters, $device);
    }

    /** @return array<string, mixed>|null */
    private function matchStreamDescriptor(string $commandName, array $parameters): ?array
    {
        return match ($commandName) {
            'query_location' => ['msg_id' => 0x8201, 'type' => 'query_location'],
            'set_mode' => [
                'msg_id' => 0x8103,
                'type' => 'set_params',
                'mode' => $parameters['mode'] ?? 3,
                'interval' => $parameters['interval'] ?? '30S',
            ],
            'set_server' => [
                'msg_id' => 0x8103,
                'type' => 'set_params',
                'host' => $parameters['host'] ?? '',
                'port' => (int) ($parameters['port'] ?? 7018),
            ],
            'set_apn' => [
                'msg_id' => 0x8103,
                'type' => 'set_params',
                'apn' => $parameters['apn'] ?? 'internet',
            ],
            'set_timezone' => [
                'msg_id' => 0x8103,
                'type' => 'set_params',
                'timezone_offset' => (int) ($parameters['offset'] ?? 0),
            ],
            default => null,
        };
    }

    private function recordStreamCommand(Device $device, string $commandName, array $descriptor, array $params): void
    {
        DeviceCommand::create([
            'device_id' => $device->id,
            'command_type' => $commandName,
            'command_payload' => json_encode($descriptor + ['params' => $params]),
            'channel' => 'jt808',
            'status' => DeviceCommandStatus::Pending,
            'requested_by' => auth()->id(),
        ]);
    }

    private function publishStreamCommand(Device $device, array $descriptor): void
    {
        $phone = $device->gsm_number;
        if (! $phone) {
            return;
        }

        try {
            Redis::connection('jt808')->publish("jt808:cmd:{$phone}", json_encode($descriptor));
        } catch (\Throwable) {
            // Stream not configured in this environment — safe to swallow.
        }
    }

    /**
     * Render the SMS body for a given command. Called by DeviceCommandObserver
     * just before dispatch via SMSConnector.
     */
    public function buildSmsBody(string $commandType, array $params): ?string
    {
        $password = $params['password'] ?? '123456';

        return match ($commandType) {
            'set_apn' => "APN{$password} ".($params['apn'] ?? 'internet'),
            'set_server', 'set_ip' => "adminip{$password} ".($params['host'] ?? '').' '.($params['port'] ?? 7018),
            'set_timezone' => "timezone{$password} ".($params['offset'] ?? 0),
            'set_mode' => $this->buildModeCommand($password, $params),
            'query_location' => "query{$password}",
            'set_family_numbers' => "familynum{$password} ".trim(($params['number1'] ?? '').' '.($params['number2'] ?? '')),
            'set_sos' => "admin{$password}".(empty($params['number']) ? '' : ' '.$params['number']),
            'set_whitelist' => "whitenum{$password} ".($params['number'] ?? ''),
            'set_alarm_mode' => "KC{$password} ".($params['mode'] ?? 2),
            'low_battery_alarm' => 'lowbattery'.$password.' '.(($params['enabled'] ?? true) ? 'on' : 'off'),
            'set_volume' => "vol{$password} ".($params['level'] ?? 4),
            'check_params' => "check{$password}",
            'check_firmware' => "ver{$password}",
            'set_wakeup_alarm' => "almclock{$password} ".($params['time'] ?? '08:00').' '.($params['days'] ?? '1111100'),
            'set_dnd' => "silent{$password} ".($params['start'] ?? '22:00').' '.($params['end'] ?? '08:00').' '.($params['days'] ?? '1111111').' 3',
            'set_sleep' => "slptime{$password} ".($params['start'] ?? '23:01').' '.($params['end'] ?? '05:31').' '.($params['days'] ?? '1111110').' 1',
            'enable_intercom' => "interon{$password} ".(($params['enabled'] ?? true) ? '1' : '0'),
            'set_intercom_group' => "group{$password} ".($params['group_name'] ?? ''),
            'set_master_number' => "admin{$password} ".($params['number'] ?? ''),
            default => null,
        };
    }

    // ── Parsers ──────────────────────────────────────────────────────────────

    private function parseSmsEvent(array $event): SignalObject
    {
        $raw = (string) ($event['raw'] ?? '');
        $eventType = $this->detectSmsEventType($raw);
        $latitude = $this->extractFloat($raw, 'Lat:');
        $longitude = $this->extractFloat($raw, 'Lon:');

        return new SignalObject(
            eventType: $eventType,
            source: SignalSource::GsmSms,
            latitude: $latitude,
            longitude: $longitude,
            speed: $this->extractSpeed($raw),
            direction: $this->extractInt($raw, 'Course:'),
            gpsFixed: str_contains($raw, 'GPS:Fixed'),
            batteryPercent: $this->extractBattery($raw),
            gsmSignal: $this->extractInt($raw, 'Signal:'),
            rawPayload: $raw,
            deviceTime: $this->extractTimestamp($raw),
        );
    }

    private function parseStreamEvent(array $event, Device $device): SignalObject
    {
        $alarmFlags = (int) ($event['alarm_flags'] ?? 0);
        $statusFlags = (int) ($event['status_flags'] ?? 0);
        $workingMode = WorkingMode::fromJt808Bits(($statusFlags >> 4) & 0b111)?->value;

        $battery = isset($event['battery_level']) ? (int) $event['battery_level'] : null;
        $battery ??= isset($event['battery_from_flags']) ? (int) $event['battery_from_flags'] : null;
        $battery ??= isset($event['battery_percent']) ? (int) $event['battery_percent'] : null;

        $signal = isset($event['signal_strength']) ? (int) $event['signal_strength'] : null;
        $signal ??= isset($event['gsm_signal']) ? (int) $event['gsm_signal'] : null;

        $deviceTime = $event['timestamp'] ?? $event['device_time'] ?? null;

        $extra = [];
        if (isset($event['acc_on'])) {
            $extra['acc_on'] = (bool) $event['acc_on'];
        }
        if (isset($event['extras'])) {
            $decoded = json_decode((string) $event['extras'], true);
            if (is_array($decoded)) {
                $extra = array_merge($extra, $decoded);
            }
        } elseif (is_array($event['extra'] ?? null)) {
            $extra = array_merge($extra, $event['extra']);
        }

        return new SignalObject(
            eventType: $this->jt808EventType($alarmFlags, $event['msg_id'] ?? null),
            source: SignalSource::StreamJt808,
            latitude: isset($event['latitude']) ? (float) $event['latitude'] : null,
            longitude: isset($event['longitude']) ? (float) $event['longitude'] : null,
            altitude: isset($event['altitude']) ? (int) $event['altitude'] : null,
            speed: isset($event['speed']) ? (float) $event['speed'] : null,
            direction: isset($event['direction']) ? (int) $event['direction'] : null,
            gpsFixed: ! empty($event['gps_fixed']),
            satellites: isset($event['satellites']) ? (int) $event['satellites'] : null,
            batteryPercent: $battery,
            gsmSignal: $signal,
            networkSignal: isset($event['network_signal']) ? (int) $event['network_signal'] : null,
            mcc: isset($event['mcc']) ? (int) $event['mcc'] : null,
            mnc: isset($event['mnc']) ? (int) $event['mnc'] : null,
            lac: isset($event['lac']) ? (int) $event['lac'] : null,
            cellId: isset($event['cell_id']) ? (int) $event['cell_id'] : null,
            workingMode: $workingMode,
            alarmFlags: $alarmFlags,
            statusFlags: $statusFlags,
            rawPayload: $event['raw'] ?? null,
            extra: $extra,
            deviceTime: $deviceTime !== null
                ? CarbonImmutable::parse((string) $deviceTime)->utc()
                : null,
        );
    }

    private function detectSmsEventType(string $raw): SignalEventType
    {
        return match (true) {
            str_contains($raw, 'Punch in!') => SignalEventType::PunchIn,
            str_contains($raw, 'Punch out!') => SignalEventType::PunchOut,
            str_contains($raw, 'SOS!') => SignalEventType::Sos,
            str_contains($raw, 'Low battery alarm!') => SignalEventType::Alarm,
            default => SignalEventType::Update,
        };
    }

    private function jt808EventType(int $alarmFlags, ?int $msgId): SignalEventType
    {
        if ($msgId === 0x0002) {
            return SignalEventType::Heartbeat;
        }
        if ($msgId === 0x0100) {
            return SignalEventType::Registration;
        }
        if ($alarmFlags & 0b0001) {
            return SignalEventType::Sos;
        }
        if ($alarmFlags & 0b0100) {
            return SignalEventType::PunchIn;
        }
        if ($alarmFlags & 0b1000) {
            return SignalEventType::PunchOut;
        }

        return SignalEventType::Update;
    }

    private function buildModeCommand(string $password, array $params): string
    {
        $mode = (int) ($params['mode'] ?? 3);
        $interval = $params['interval'] ?? match ($mode) {
            0 => '',
            1 => '20M',
            2 => '60S',
            3 => '30S',
            default => '30S',
        };

        return $mode === 0
            ? "md{$password} 0"
            : "md{$password} {$mode} {$interval}";
    }

    private function extractFloat(string $raw, string $key): ?float
    {
        if (preg_match('/'.preg_quote($key, '/').'([\-\d.]+)/', $raw, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    private function extractInt(string $raw, string $key): ?int
    {
        if (preg_match('/'.preg_quote($key, '/').'(\d+)/', $raw, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function extractSpeed(string $raw): ?float
    {
        if (preg_match('/Speed:([\d.]+)km\/h/', $raw, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    private function extractBattery(string $raw): ?int
    {
        if (preg_match('/Battery:(\d+)%/', $raw, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function extractTimestamp(string $raw): ?CarbonImmutable
    {
        if (preg_match('/T:([\d\-: ]+)/', $raw, $m)) {
            try {
                return CarbonImmutable::parse(trim($m[1]))->utc();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
