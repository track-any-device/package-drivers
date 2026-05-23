<?php

declare(strict_types=1);

namespace TrackAnyDevice\Drivers\Contracts;

use TrackAnyDevice\Drivers\ValueObjects\AddOnCommand;
use TrackAnyDevice\Drivers\ValueObjects\SignalObject;
use TrackAnyDevice\Core\Models\Device;

/**
 * Contract that every device driver must implement.
 *
 * Drivers are the only place where device-specific protocol knowledge lives.
 * The platform talks to drivers through SignalObject (incoming) and
 * AddOnCommand (outgoing) — both protocol-agnostic value objects.
 *
 * Drivers MUST be stateless and side-effect-free in their parse methods.
 * Commands and onboarding may invoke connectors that do I/O.
 */
interface DeviceDriverInterface
{
    // ── Stream ────────────────────────────────────────────────────────────

    /**
     * Stream channel this driver listens on.
     *
     * @return 'jt808'|'gt06'|'h02'|'gps103'|'none'
     */
    public function getStreamChannel(): string;

    /**
     * Whether the given device currently has an active stream connection.
     * Drivers that do not support streaming should always return false.
     */
    public function supportsStream(Device $device): bool;

    // ── Core Actions ──────────────────────────────────────────────────────

    /**
     * Parse a raw incoming event (from stream or callback) into a normalised
     * SignalObject. Must never throw — return a partial object with whatever
     * fields can be extracted.
     *
     * @param  array<string, mixed>  $rawEvent
     */
    public function parseEventToSignal(array $rawEvent, Device $device): SignalObject;

    /**
     * Parse an SMS body received in the device's inbox into a SignalObject.
     *
     * This is the dedicated path for SMS-only devices (e.g. GF-07) and for
     * stream-capable devices whose stream is unavailable (e.g. P901 falling
     * back to SMS when the JT808 socket is offline). Drivers that never
     * receive SMS replies (pure stream-only) may still implement this to
     * return a minimal SignalObject — the contract is uniform so the
     * IncomingSmsObserver can stay protocol-agnostic.
     *
     * Must never throw — return a partial SignalObject with whatever fields
     * can be extracted from the raw text.
     */
    public function parseSmsToSignal(string $rawSms, Device $device): SignalObject;

    /**
     * Request a specific signal from the device.
     *
     * Implementations prefer the stream channel when supportsStream() is
     * true, falling back to a GSM SMS command otherwise.
     */
    public function requestSignal(string $signalType, Device $device): void;

    // ── Mode Management ───────────────────────────────────────────────────

    /**
     * Set the device's tracking / operating mode.
     *
     * @param  array<string, mixed>  $params
     */
    public function setMode(string $mode, Device $device, array $params = []): void;

    /** Return the device's currently known mode, if any. */
    public function getMode(Device $device): ?string;

    // ── Onboarding ────────────────────────────────────────────────────────

    /**
     * Run the one-time onboarding sequence for a freshly-provisioned device:
     * point it at the platform's gateway and server, sync APN/timezone, and
     * set the default tracking mode. Implementations are expected to queue
     * SMS commands rather than send them synchronously.
     */
    public function onboardingAction(Device $device): void;

    // ── Add-on Commands ───────────────────────────────────────────────────

    /**
     * Driver-specific commands exposed in the Filament UI.
     *
     * @return list<AddOnCommand>
     */
    public function addOnCommands(): array;

    /**
     * Execute a named add-on command with parameters previously declared via
     * addOnCommands(). Implementations should prefer stream and fall back to
     * GSM SMS where the channel allows.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function addOnCommand(string $commandName, array $parameters, Device $device): void;
}
