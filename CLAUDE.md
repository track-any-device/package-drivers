# package-drivers — AI Instructions

This is the **GPS device driver abstraction** for the Track Any Device platform.
Packagist: `track-any-device/drivers` | Namespace: `TrackAnyDevice\Drivers\`

Device drivers parse raw protocol data (JT808 frames, SMS payloads) into normalised
`SignalObject` value objects, and translate outbound commands into protocol-specific
payloads. The platform never deals with raw protocol bytes — only with `SignalObject`.

Read this file before making any change.

---

## Platform-Wide Rules

These three rules apply in every repository under the `track-any-device` organisation.

**Cross-repo changes: file a GitHub issue first.**
If a task in this repository requires a change in another package or server app — stop. Open a
GitHub issue in the target repository describing exactly what is needed and why. Reference that
issue number in your commit message (`ref track-any-device/{repo}#{n}`). Do not directly edit
files in another repository. When picking up a cross-repo issue, run Claude locally inside that
repository's working directory and work only within its scope.

**Release order: packages before server apps.**
This package depends on `package-core`. Release order: `package-core → package-drivers → server apps`.
Tag here before bumping the constraint in any server app.

**Database layer lives in `package-core` only.**
No migrations or model classes here. Drivers are stateless protocol translators.

---

## Rule 1 — Plan before implementing

Before writing any code, ask clarifying questions. Present a plan and get explicit agreement.
Only begin once the approach is confirmed.

---

## Architecture

All drivers implement `DeviceDriverInterface`:

```php
interface DeviceDriverInterface
{
    public function parseSignal(mixed $raw): SignalObject;
    public function buildCommand(string $command, array $params): string;
    public function onboardingSequence(Device $device): array; // returns array of command strings
}
```

`SignalObject` is a normalised value object:
```php
class SignalObject
{
    public float $lat;
    public float $lon;
    public ?float $speed;
    public ?float $heading;
    public ?int $battery;
    public ?float $temperature;
    public array $alarms;   // ['sos', 'low_battery', 'geofence_exit', ...]
    public Carbon $recordedAt;
}
```

---

## Current Drivers

| Class | Device | Protocol |
|---|---|---|
| `GF07Driver` | GF-07 Mini GPS Tracker | SMS only |
| `AOT120Driver` | AOT120 Vehicle GPS Tracker | JT808 TCP |
| `P901Driver` | Cantrack P901 Smart ID Card | JT808 TCP |

---

## Rule 2 — New drivers must implement the full interface

Adding support for a new device type means:
1. Creating a class that implements `DeviceDriverInterface`
2. Registering it in `DriversServiceProvider` keyed by the `DeviceType` slug
3. Writing unit tests that cover `parseSignal()` and `buildCommand()` with real fixture data
4. Filing an issue against `package-core` if a new `DeviceType` seeder entry is needed

Never add device-specific parsing logic inside `package-core` or server apps.

---

## Rule 3 — Drivers are stateless

Drivers must not persist data, fire events, or inject services that write to the database.
`parseSignal()` returns a `SignalObject` — the caller (`SignalService` in `package-core`)
decides what to do with it.

---

## Dependencies

```
track-any-device/core (for SignalObject, Device model)
```

---

## Versioning

Tags are created automatically on merge to `main`. Default bump is `patch`.
