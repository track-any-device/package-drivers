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
 * Driver for the AOT120 vehicle-grade GPS tracker.
 *
 * Telemetry: JT/T 808-2019 binary frames over TCP (`jt808` stream channel).
 * Configuration: GSM SMS commands of the form `KEYWORD,<password>,<args>#` or
 * `KEYWORD#`. Full spec lives in docs/devices/aot120.md.
 *
 * Routing rules: every method that can target the live socket prefers the
 * stream when `supportsStream()` is true, else falls back to a queued SMS.
 */
class AOT120Driver implements DeviceDriverInterface
{
    use QueuesSmsCommands;

    public function getStreamChannel(): string
    {
        return 'jt808';
    }

    public function supportsStream(Device $device): bool
    {
        return $device->last_signal_at !== null
            && $device->last_signal_at->isAfter(now()->subMinutes(10));
    }

    // ── Parsers ──────────────────────────────────────────────────────────────

    public function parseEventToSignal(array $rawEvent, Device $device): SignalObject
    {
        $source = $rawEvent['source'] ?? SignalSource::StreamJt808->value;

        return match ($source) {
            SignalSource::StreamJt808->value => $this->parseStreamEvent($rawEvent),
            default => $this->parseSmsToSignal((string) ($rawEvent['raw'] ?? ''), $device),
        };
    }

    public function parseSmsToSignal(string $rawSms, Device $device): SignalObject
    {
        [$latitude, $longitude] = $this->extractCoordinates($rawSms);

        return new SignalObject(
            eventType: $this->detectSmsEventType($rawSms),
            source: SignalSource::GsmSms,
            latitude: $latitude,
            longitude: $longitude,
            speed: $this->extractFloat($rawSms, 'Speed:'),
            direction: $this->extractInt($rawSms, 'Course:'),
            gpsFixed: $latitude !== null,
            batteryPercent: $this->extractBattery($rawSms),
            gsmSignal: $this->extractInt($rawSms, 'Signal:'),
            rawPayload: $rawSms,
        );
    }

    // ── Outgoing ─────────────────────────────────────────────────────────────

    public function requestSignal(string $signalType, Device $device): void
    {
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

        if ($masterNumber !== '') {
            $this->queueSms('set_center_number', ['number' => $masterNumber], $device);
        }

        $this->queueSms('set_server', ['host' => $host, 'port' => $port], $device);
        $this->queueSms('set_apn', ['apn' => $apn], $device);
        $this->queueSms('set_upload_interval', ['seconds' => 30], $device);
        $this->queueSms('check_params', [], $device);
    }

    public function addOnCommands(): array
    {
        return [
            // Tracking
            new AddOnCommand('query_location', 'Query Location', [], 'tracking', false),
            new AddOnCommand('set_mode', 'Set Tracking Mode', ['mode' => ['type' => 'select', 'options' => [0, 1, 2, 3]], 'interval' => ['type' => 'integer']], 'tracking', false),
            new AddOnCommand('set_upload_interval', 'Set Heartbeat Interval', ['seconds' => ['type' => 'integer', 'required' => true]], 'tracking', false),

            // Network
            new AddOnCommand('set_apn', 'Set APN', ['apn' => ['type' => 'string', 'required' => true]], 'network', true),
            new AddOnCommand('set_server', 'Set Server IP/Port', ['host' => ['type' => 'string', 'required' => true], 'port' => ['type' => 'integer', 'required' => true]], 'network', true),
            new AddOnCommand('set_center_number', 'Set Center Number', ['number' => ['type' => 'string', 'required' => true]], 'network', true),

            // Alarms & security
            new AddOnCommand('engine_cut', 'Cut Engine Power', [], 'security', true),
            new AddOnCommand('engine_restore', 'Restore Engine Power', [], 'security', true),
            new AddOnCommand('set_vibration_alarm', 'Vibration Alarm', ['sensitivity' => ['type' => 'integer', 'required' => true], 'duration' => ['type' => 'integer', 'required' => true]], 'alarm', true),
            new AddOnCommand('set_speeding_alarm', 'Speeding Alarm', ['speed_limit' => ['type' => 'integer', 'required' => true], 'duration' => ['type' => 'integer', 'required' => true]], 'alarm', true),
            new AddOnCommand('set_fatigue_alarm', 'Fatigue Driving Alarm', ['fatigue_time' => ['type' => 'integer', 'required' => true], 'rest_time' => ['type' => 'integer', 'required' => true]], 'alarm', true),

            // System
            new AddOnCommand('status', 'Device Status', [], 'utility', true),
            new AddOnCommand('check_version', 'Firmware Version', [], 'utility', true),
            new AddOnCommand('check_imei', 'Read IMEI', [], 'utility', true),
            new AddOnCommand('check_params', 'Read Parameters', [], 'utility', true),
            new AddOnCommand('reset', 'Restart Device', [], 'utility', true),
            new AddOnCommand('factory_reset', 'Factory Reset', [], 'utility', true),
        ];
    }

    public function addOnCommand(string $commandName, array $parameters, Device $device): void
    {
        $descriptor = $this->matchStreamDescriptor($commandName, $parameters);

        if ($descriptor !== null && $this->supportsStream($device)) {
            $this->recordStreamCommand($device, $commandName, $descriptor, $parameters);
            $this->publishStreamCommand($device, $descriptor);

            return;
        }

        $this->queueSms($commandName, $parameters, $device);
    }

    public function buildSmsBody(string $commandType, array $params): ?string
    {
        $password = $params['password'] ?? '123456';

        return match ($commandType) {
            // Location
            'query_location' => 'WHERE#',
            'query_location_url' => 'URL#',
            // Config
            'set_apn' => "APN,{$password},".($params['apn'] ?? 'internet').'#',
            'set_server', 'set_ip' => "SERVER,{$password},".($params['host'] ?? '').','.($params['port'] ?? 7018).',0#',
            'set_center_number', 'set_master_number' => 'CENTER,A,'.($params['number'] ?? '').'#',
            'reset', 'restart' => 'RESET#',
            // Alarms
            'engine_cut' => 'RELAY,1#',
            'engine_restore' => 'RELAY,0#',
            'set_vibration_alarm' => 'VIBRATION,'.($params['sensitivity'] ?? 2).','.($params['duration'] ?? 3).'#',
            'set_speeding_alarm' => 'SPEEDING,'.($params['speed_limit'] ?? 80).','.($params['duration'] ?? 10).'#',
            'set_fatigue_alarm' => 'dr,'.($params['fatigue_time'] ?? 14400).','.($params['rest_time'] ?? 1200).'#',
            // System
            'status' => 'STATUS#',
            'check_version' => 'VERSION#',
            'check_imei' => 'IMEI#',
            'check_params' => 'PARAM#',
            'factory_reset' => 'FACTORY#',
            'set_upload_interval' => 'UPLOAD,'.((int) ($params['seconds'] ?? 30)).'#',
            // set_mode falls through to UPLOAD since AOT120 doesn't have P901-style modes
            'set_mode' => 'UPLOAD,'.((int) ($params['interval'] ?? 30)).'#',
            default => null,
        };
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function parseStreamEvent(array $event): SignalObject
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

    /** @return array<string, mixed>|null */
    private function matchStreamDescriptor(string $commandName, array $parameters): ?array
    {
        return match ($commandName) {
            'query_location' => ['msg_id' => 0x8201, 'type' => 'query_location'],
            'set_mode', 'set_upload_interval' => [
                'msg_id' => 0x8103,
                'type' => 'set_params',
                'heartbeat_interval' => (int) ($parameters['seconds'] ?? $parameters['interval'] ?? 30),
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
            'reset' => ['msg_id' => 0x8105, 'type' => 'restart'],
            'factory_reset' => ['msg_id' => 0x8105, 'type' => 'factory_reset'],
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
            // Stream not configured in this environment.
        }
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
        if ($alarmFlags & 0b0010) {
            // Overspeed → surface as a generic alarm; ProcessAlarmEvents handles it.
            return SignalEventType::Alarm;
        }

        return SignalEventType::Update;
    }

    private function detectSmsEventType(string $raw): SignalEventType
    {
        return match (true) {
            str_contains($raw, 'SOS!') => SignalEventType::Sos,
            str_contains($raw, 'Vibration!'), str_contains($raw, 'Speeding!'), str_contains($raw, 'Fatigue!') => SignalEventType::Alarm,
            default => SignalEventType::Update,
        };
    }

    /** @return array{float|null, float|null} */
    private function extractCoordinates(string $raw): array
    {
        if (preg_match('/\?q=(-?\d+\.\d+),(-?\d+\.\d+)/', $raw, $m)) {
            return [(float) $m[1], (float) $m[2]];
        }
        if (preg_match('/Lat:\s*([\-\d.]+).*?Lon:\s*([\-\d.]+)/s', $raw, $m)) {
            return [(float) $m[1], (float) $m[2]];
        }

        return [null, null];
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

    private function extractBattery(string $raw): ?int
    {
        if (preg_match('/Battery:\s*(\d+)/i', $raw, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
