<?php

declare(strict_types=1);

namespace TrackAnyDevice\Drivers\ValueObjects;

use TrackAnyDevice\Core\Enums\SignalEventType;
use TrackAnyDevice\Core\Enums\SignalSource;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Normalised telemetry record produced by a device driver.
 *
 * All timestamps are UTC. `serverTime` is set when the signal is persisted
 * via SignalService and reflects when the platform received the event.
 * `deviceTime` is the device's reported clock (may differ from server clock).
 *
 * Field nullability mirrors the InfluxDB `signal` measurement schema —
 * drivers populate whatever they can parse and leave the rest null.
 */
readonly class SignalObject
{
    public function __construct(
        public SignalEventType $eventType,
        public SignalSource $source,
        // GPS
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?int $altitude = null,
        public ?float $speed = null,
        public ?int $direction = null,
        public bool $gpsFixed = false,
        public ?int $satellites = null,
        public ?string $positioningType = null,
        public ?float $hdop = null,
        // Battery
        public ?int $batteryPercent = null,
        public ?int $batteryVoltage = null,
        public ?int $batteryCapacityMah = null,
        public ?string $batteryLength = null,
        // Network
        public ?int $gsmSignal = null,
        public ?int $networkSignal = null,
        public ?int $mcc = null,
        public ?int $mnc = null,
        public ?int $lac = null,
        public ?int $cellId = null,
        // Device state
        public ?string $workingMode = null,
        public ?int $alarmFlags = null,
        public ?int $statusFlags = null,
        // Misc
        public ?float $level = null,
        public ?float $temperature = null,
        public ?string $rawPayload = null,
        public array $extra = [],
        public ?CarbonImmutable $deviceTime = null,
        public ?CarbonImmutable $serverTime = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            eventType: $data['event_type'] instanceof SignalEventType
                ? $data['event_type']
                : SignalEventType::from($data['event_type'] ?? SignalEventType::Update->value),
            source: $data['source'] instanceof SignalSource
                ? $data['source']
                : SignalSource::from($data['source'] ?? SignalSource::Api->value),
            latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
            longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
            altitude: isset($data['altitude']) ? (int) $data['altitude'] : null,
            speed: isset($data['speed']) ? (float) $data['speed'] : null,
            direction: isset($data['direction']) ? (int) $data['direction'] : null,
            gpsFixed: (bool) ($data['gps_fixed'] ?? false),
            satellites: isset($data['satellites']) ? (int) $data['satellites'] : null,
            positioningType: $data['positioning_type'] ?? null,
            hdop: isset($data['hdop']) ? (float) $data['hdop'] : null,
            batteryPercent: isset($data['battery_percent']) ? (int) $data['battery_percent'] : null,
            batteryVoltage: isset($data['battery_voltage']) ? (int) $data['battery_voltage'] : null,
            batteryCapacityMah: isset($data['battery_capacity_mah']) ? (int) $data['battery_capacity_mah'] : null,
            batteryLength: $data['battery_length'] ?? null,
            gsmSignal: isset($data['gsm_signal']) ? (int) $data['gsm_signal'] : null,
            networkSignal: isset($data['network_signal']) ? (int) $data['network_signal'] : null,
            mcc: isset($data['mcc']) ? (int) $data['mcc'] : null,
            mnc: isset($data['mnc']) ? (int) $data['mnc'] : null,
            lac: isset($data['lac']) ? (int) $data['lac'] : null,
            cellId: isset($data['cell_id']) ? (int) $data['cell_id'] : null,
            workingMode: $data['working_mode'] ?? null,
            alarmFlags: isset($data['alarm_flags']) ? (int) $data['alarm_flags'] : null,
            statusFlags: isset($data['status_flags']) ? (int) $data['status_flags'] : null,
            level: isset($data['level']) ? (float) $data['level'] : null,
            temperature: isset($data['temperature']) ? (float) $data['temperature'] : null,
            rawPayload: $data['raw_payload'] ?? null,
            extra: (array) ($data['extra'] ?? []),
            deviceTime: self::toCarbon($data['device_time'] ?? null),
            serverTime: self::toCarbon($data['server_time'] ?? null),
        );
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function withServerTime(CarbonImmutable $time): self
    {
        return new self(
            eventType: $this->eventType,
            source: $this->source,
            latitude: $this->latitude,
            longitude: $this->longitude,
            altitude: $this->altitude,
            speed: $this->speed,
            direction: $this->direction,
            gpsFixed: $this->gpsFixed,
            satellites: $this->satellites,
            positioningType: $this->positioningType,
            hdop: $this->hdop,
            batteryPercent: $this->batteryPercent,
            batteryVoltage: $this->batteryVoltage,
            batteryCapacityMah: $this->batteryCapacityMah,
            batteryLength: $this->batteryLength,
            gsmSignal: $this->gsmSignal,
            networkSignal: $this->networkSignal,
            mcc: $this->mcc,
            mnc: $this->mnc,
            lac: $this->lac,
            cellId: $this->cellId,
            workingMode: $this->workingMode,
            alarmFlags: $this->alarmFlags,
            statusFlags: $this->statusFlags,
            level: $this->level,
            temperature: $this->temperature,
            rawPayload: $this->rawPayload,
            extra: $this->extra,
            deviceTime: $this->deviceTime,
            serverTime: $time,
        );
    }

    public function toArray(): array
    {
        return [
            'event_type' => $this->eventType->value,
            'source' => $this->source->value,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'altitude' => $this->altitude,
            'speed' => $this->speed,
            'direction' => $this->direction,
            'gps_fixed' => $this->gpsFixed,
            'satellites' => $this->satellites,
            'positioning_type' => $this->positioningType,
            'hdop' => $this->hdop,
            'battery_percent' => $this->batteryPercent,
            'battery_voltage' => $this->batteryVoltage,
            'battery_capacity_mah' => $this->batteryCapacityMah,
            'battery_length' => $this->batteryLength,
            'gsm_signal' => $this->gsmSignal,
            'network_signal' => $this->networkSignal,
            'mcc' => $this->mcc,
            'mnc' => $this->mnc,
            'lac' => $this->lac,
            'cell_id' => $this->cellId,
            'working_mode' => $this->workingMode,
            'alarm_flags' => $this->alarmFlags,
            'status_flags' => $this->statusFlags,
            'level' => $this->level,
            'temperature' => $this->temperature,
            'raw_payload' => $this->rawPayload,
            'extra' => $this->extra,
            'device_time' => $this->deviceTime?->toIso8601ZuluString(),
            'server_time' => $this->serverTime?->toIso8601ZuluString(),
        ];
    }

    private static function toCarbon(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonImmutable) {
            return $value->utc();
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        return CarbonImmutable::parse((string) $value)->utc();
    }
}
