# track-any-device/drivers

Device drivers and connectors for the Track Any Device platform.

Each driver encapsulates all protocol knowledge for a specific GPS tracker model — parsing incoming telemetry (stream or SMS), building outgoing commands, and managing device onboarding. The rest of the platform talks to every device through a single uniform interface.

---

## Requirements

- PHP 8.3+
- Laravel 13.7+
- `track-any-device/core` ^0.0.2

---

## Installation

```bash
composer require track-any-device/drivers
```

The service provider is auto-discovered by Laravel.

---

## Included drivers

| Driver class | Device | Stream channel | SMS fallback |
|---|---|---|---|
| `GF07Driver` | GF-07 Mini GPS Tracker | — (SMS only) | yes |
| `AOT120Driver` | AOT120 Vehicle GPS Tracker | JT/T 808 TCP | yes |
| `P901Driver` | Cantrack P901 Smart ID Card | JT/T 808 TCP | yes |

---

## Core concepts

### `DeviceDriverInterface`

Every driver implements `TrackAnyDevice\Drivers\Contracts\DeviceDriverInterface`. You interact with any device through this contract without needing to know which driver is underneath.

```php
use TrackAnyDevice\Drivers\Contracts\DeviceDriverInterface;

class HandleIncomingSignal
{
    public function __construct(private DeviceDriverInterface $driver) {}

    public function handle(array $rawEvent, Device $device): void
    {
        $signal = $this->driver->parseEventToSignal($rawEvent, $device);

        // $signal is a normalised SignalObject regardless of device model
    }
}
```

### `SignalObject`

A readonly value object that carries normalised telemetry. Drivers populate whatever fields they can parse and leave the rest `null`.

```php
$signal->eventType;       // SignalEventType enum
$signal->source;          // SignalSource enum (stream_jt808 | gsm_sms | …)
$signal->latitude;        // ?float
$signal->longitude;       // ?float
$signal->speed;           // ?float  km/h
$signal->direction;       // ?int    degrees
$signal->gpsFixed;        // bool
$signal->batteryPercent;  // ?int
$signal->gsmSignal;       // ?int
$signal->workingMode;     // ?string
$signal->alarmFlags;      // ?int    bitmask
$signal->deviceTime;      // ?CarbonImmutable (UTC)
$signal->rawPayload;      // ?string original frame / SMS body
```

Check for a usable position:

```php
if ($signal->hasLocation()) {
    // $signal->latitude and $signal->longitude are both non-null
}
```

Stamp with server receive time (done by `SignalService`):

```php
$signal = $signal->withServerTime(CarbonImmutable::now());
```

Serialise / deserialise:

```php
$array  = $signal->toArray();
$signal = SignalObject::fromArray($array);
```

---

## Parsing incoming telemetry

### Stream events (JT808)

The JT808 TCP server decodes binary frames and publishes a decoded array. Pass it straight to the driver:

```php
$signal = $driver->parseEventToSignal([
    'source'       => 'stream_jt808',
    'msg_id'       => 0x0200,
    'latitude'     => 51.5074,
    'longitude'    => -0.1278,
    'speed'        => 32.5,
    'direction'    => 270,
    'gps_fixed'    => true,
    'alarm_flags'  => 0,
    'status_flags' => 0,
    'device_time'  => '2025-06-01 12:00:00',
], $device);
```

### SMS replies

Pass the raw SMS body as received from the gateway:

```php
$signal = $driver->parseSmsToSignal(
    'Lat:51.5074 Lon:-0.1278 Speed:32km/h Battery:78% Signal:4 GPS:Fixed',
    $device
);
```

For drivers that only receive SMS (e.g. GF-07), you can also pass an event array with a `raw` key:

```php
$signal = $driver->parseEventToSignal(['raw' => $smsBody], $device);
```

---

## Sending commands

### Request a location fix

```php
$driver->requestSignal('location', $device);
```

JT808 drivers send a `0x8201` query over the live socket when the device is online (last signal within 10 minutes), falling back to an SMS command when it is not.

### Set tracking mode

```php
$driver->setMode('3', $device, ['interval' => '30S']);
```

### Add-on commands

Each driver declares its full command catalogue via `addOnCommands()`, which the Filament UI uses to render the command panel:

```php
/** @var list<AddOnCommand> */
$commands = $driver->addOnCommands();

foreach ($commands as $cmd) {
    $cmd->name;        // string  machine name
    $cmd->label;       // string  human label
    $cmd->params;      // array   JSON-schema-like param descriptors
    $cmd->category;    // string  'tracking' | 'network' | 'alarm' | 'utility' | …
    $cmd->requiresGsm; // bool    UI hint: command cannot use the stream channel
}
```

Execute a named command:

```php
$driver->addOnCommand('set_apn', ['apn' => 'internet'], $device);
$driver->addOnCommand('engine_cut', [], $device);
$driver->addOnCommand('set_volume', ['level' => 6], $device);
```

---

## Onboarding a new device

Call `onboardingAction` once after a device is provisioned. It queues the full setup sequence (server IP, APN, timezone, default tracking mode) as SMS commands via `DeviceCommandObserver`:

```php
$driver->onboardingAction($device);
```

Commands are dispatched asynchronously — the observer picks up each `DeviceCommand` row and routes it through `SMSConnector`.

---

## Stream routing

JT808 drivers (`AOT120Driver`, `P901Driver`) determine whether the device is reachable over the live socket by checking `last_signal_at`:

```php
// true when device has reported within the last 10 minutes
$driver->supportsStream($device);

// 'jt808' | 'none'
$driver->getStreamChannel();
```

When the stream is available, commands are published to a Redis pub/sub channel:

```
jt808:cmd:{gsm_number}
```

The JT808 TCP server subscribes to this channel and writes the encoded frame to the open socket.

---

## Adding a new driver

1. Create a class in `src/` that implements `DeviceDriverInterface`.
2. Use the `QueuesSmsCommands` trait if the driver sends SMS commands.
3. Implement `buildSmsBody(string $commandType, array $params): ?string` — return the raw SMS string for each command type, or `null` to skip.
4. Register the driver in your application's `DriverRegistry` (provided by `track-any-device/core`).

```php
use TrackAnyDevice\Drivers\Concerns\QueuesSmsCommands;
use TrackAnyDevice\Drivers\Contracts\DeviceDriverInterface;
use TrackAnyDevice\Drivers\ValueObjects\AddOnCommand;
use TrackAnyDevice\Drivers\ValueObjects\SignalObject;
use TrackAnyDevice\Core\Models\Device;

class MyTrackerDriver implements DeviceDriverInterface
{
    use QueuesSmsCommands;

    public function getStreamChannel(): string { return 'none'; }
    public function supportsStream(Device $device): bool { return false; }

    public function parseEventToSignal(array $rawEvent, Device $device): SignalObject
    {
        return $this->parseSmsToSignal((string) ($rawEvent['raw'] ?? ''), $device);
    }

    public function parseSmsToSignal(string $rawSms, Device $device): SignalObject
    {
        // parse $rawSms and return a SignalObject — never throw
        return new SignalObject(
            eventType: SignalEventType::Update,
            source: SignalSource::GsmSms,
            rawPayload: $rawSms,
        );
    }

    public function requestSignal(string $signalType, Device $device): void
    {
        $this->queueSms('query_location', [], $device);
    }

    public function setMode(string $mode, Device $device, array $params = []): void {}
    public function getMode(Device $device): ?string { return null; }
    public function onboardingAction(Device $device): void {}

    public function addOnCommands(): array { return []; }
    public function addOnCommand(string $commandName, array $parameters, Device $device): void
    {
        $this->queueSms($commandName, $parameters, $device);
    }

    public function buildSmsBody(string $commandType, array $params): ?string
    {
        return match ($commandType) {
            'query_location' => 'WHERE#',
            default          => null,
        };
    }
}
```

---

## SMSConnector

`TrackAnyDevice\Drivers\Connectors\SMSConnector` implements `DeviceConnectorInterface` and dispatches outgoing messages through `SmsGatewayService`. It is resolved from the container automatically when bound:

```php
// In a service provider
$this->app->bind(DeviceConnectorInterface::class, SMSConnector::class);
```

The connector updates the `DeviceCommand` status to `Queued` → `Sent` (or `Failed`) as the message progresses.

---

## Changelog

See [GitHub Releases](https://github.com/track-any-device/package-drivers/releases).

## Licence

MIT
