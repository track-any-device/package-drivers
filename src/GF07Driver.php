<?php

declare(strict_types=1);

namespace TrackAnyDevice\Drivers;

use TrackAnyDevice\Drivers\Concerns\QueuesSmsCommands;
use TrackAnyDevice\Drivers\Contracts\DeviceDriverInterface;
use TrackAnyDevice\Drivers\ValueObjects\AddOnCommand;
use TrackAnyDevice\Drivers\ValueObjects\SignalObject;
use TrackAnyDevice\Core\Enums\SignalEventType;
use TrackAnyDevice\Core\Enums\SignalSource;
use TrackAnyDevice\Core\Models\Device;

/**
 * Driver for the GF-07 Mini GPS Tracker — SMS-only, no password, no stream.
 *
 * Outgoing commands are bare numeric codes:
 *   000 — pair admin phone number
 *   444 — cancel all previous commands
 *   445 — erase memory card contents
 *   555 — start audio recording
 *   666 — activate live microphone (device calls back)
 *   777 — query current location
 *   888 — query status (battery, signal, memory)
 *   999 — restart device
 *
 * Incoming SMS bodies (location reply, status reply, low-battery alarm) are
 * parsed via {@see parseSmsToSignal}.
 */
class GF07Driver implements DeviceDriverInterface
{
    use QueuesSmsCommands;

    public function getStreamChannel(): string
    {
        return 'none';
    }

    public function supportsStream(Device $device): bool
    {
        return false;
    }

    public function parseEventToSignal(array $rawEvent, Device $device): SignalObject
    {
        // GF-07 never streams — every "event" is an SMS reply.
        return $this->parseSmsToSignal((string) ($rawEvent['raw'] ?? ''), $device);
    }

    public function parseSmsToSignal(string $rawSms, Device $device): SignalObject
    {
        [$latitude, $longitude] = $this->extractCoordinates($rawSms);

        return new SignalObject(
            eventType: $this->detectEventType($rawSms),
            source: SignalSource::GsmSms,
            latitude: $latitude,
            longitude: $longitude,
            gpsFixed: $latitude !== null,
            batteryPercent: $this->extractBattery($rawSms),
            gsmSignal: $this->extractSignalStrength($rawSms),
            rawPayload: $rawSms,
        );
    }

    public function requestSignal(string $signalType, Device $device): void
    {
        $this->queueSms('query_location', [], $device);
    }

    public function setMode(string $mode, Device $device, array $params = []): void
    {
        // GF-07 has no configurable tracking mode.
    }

    public function getMode(Device $device): ?string
    {
        return null;
    }

    public function onboardingAction(Device $device): void
    {
        // GF-07 pairs by calling its number from the admin phone. Best we can
        // do is queue the pair command and let the user place the call.
        $this->queueSms('pair', [], $device);
    }

    public function addOnCommands(): array
    {
        return [
            new AddOnCommand('query_location', 'Query Location', [], 'tracking', true),
            new AddOnCommand('query_status', 'Query Status', [], 'utility', true),
            new AddOnCommand('pair', 'Pair Admin Number', [], 'utility', true),
            new AddOnCommand('cancel', 'Cancel Pending Commands', [], 'utility', true),
            new AddOnCommand('restart', 'Restart Device', [], 'utility', true),
            new AddOnCommand('start_recording', 'Start Recording', [], 'utility', true),
            new AddOnCommand('live_mic', 'Activate Live Mic', [], 'utility', true),
            new AddOnCommand('erase_memory', 'Erase Memory', [], 'utility', true),
        ];
    }

    public function addOnCommand(string $commandName, array $parameters, Device $device): void
    {
        $this->queueSms($commandName, $parameters, $device);
    }

    public function buildSmsBody(string $commandType, array $params): ?string
    {
        return match ($commandType) {
            'query_location' => '777',
            'query_status', 'check' => '888',
            'pair', 'set_master_number' => '000',
            'cancel' => '444',
            'erase_memory' => '445',
            'start_recording' => '555',
            'live_mic' => '666',
            'restart' => '999',
            default => null,
        };
    }

    // ── Parsers ──────────────────────────────────────────────────────────────

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

    private function extractBattery(string $raw): ?int
    {
        if (preg_match('/Battery:\s*(\d+)/i', $raw, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function extractSignalStrength(string $raw): ?int
    {
        if (preg_match('/Signal:\s*(\d+)/i', $raw, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function detectEventType(string $raw): SignalEventType
    {
        return match (true) {
            str_contains($raw, 'Low battery!') => SignalEventType::Alarm,
            str_contains($raw, 'Recording started') => SignalEventType::Update,
            default => SignalEventType::Update,
        };
    }
}
