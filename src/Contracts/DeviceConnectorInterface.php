<?php

namespace TrackAnyDevice\Drivers\Contracts;

use TrackAnyDevice\Core\Models\Device;
use TrackAnyDevice\Core\Models\DeviceCommand;

interface DeviceConnectorInterface
{
    /**
     * Dispatch the outgoing message for the given command.
     *
     * For SMS: sends to device.sim_number via the configured SMS gateway.
     * For TCP/socket: writes to the active connection (future).
     *
     * Implementations must update command status to Queued/Sent as appropriate.
     */
    public function send(Device $device, DeviceCommand $command, string $message): void;
}
