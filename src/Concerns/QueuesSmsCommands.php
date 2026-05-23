<?php

declare(strict_types=1);

namespace TrackAnyDevice\Drivers\Concerns;

use TrackAnyDevice\Core\Enums\DeviceCommandStatus;
use TrackAnyDevice\Core\Models\Device;
use TrackAnyDevice\Core\Models\DeviceCommand;

/**
 * Shared SMS-queueing for drivers whose outgoing channel is the SMS gateway.
 *
 * Creates a DeviceCommand row; the existing DeviceCommandObserver picks it up
 * and routes it through SMSConnector. Drivers using this trait MUST expose a
 * public `buildSmsBody(string $commandType, array $params): ?string` method —
 * the observer calls it to materialise the SMS body before dispatch.
 */
trait QueuesSmsCommands
{
    protected function queueSms(string $commandType, array $params, Device $device): DeviceCommand
    {
        return DeviceCommand::create([
            'device_id' => $device->id,
            'command_type' => $commandType,
            'command_payload' => json_encode($params, JSON_UNESCAPED_SLASHES),
            'channel' => 'sms',
            'status' => DeviceCommandStatus::Pending,
            'requested_by' => auth()->id(),
        ]);
    }
}
