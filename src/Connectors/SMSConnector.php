<?php

namespace TrackAnyDevice\Drivers\Connectors;

use TrackAnyDevice\Drivers\Contracts\DeviceConnectorInterface;
use TrackAnyDevice\Core\Enums\DeviceCommandStatus;
use TrackAnyDevice\Core\Models\Device;
use TrackAnyDevice\Core\Models\DeviceCommand;
use TrackAnyDevice\SmsGateway\SmsGatewayService;
use Illuminate\Support\Facades\Log;

class SMSConnector implements DeviceConnectorInterface
{
    public function __construct(private readonly SmsGatewayService $gateway) {}

    public function send(Device $device, DeviceCommand $command, string $message): void
    {
        $gsm = $device->gsm_number;

        if (empty($gsm)) {
            Log::warning('SMSConnector: no GSM number on device, command skipped', [
                'device_id' => $device->id,
                'command_type' => $command->command_type,
            ]);

            $command->update([
                'status' => DeviceCommandStatus::Failed,
                'command_payload' => $message,
            ]);

            return;
        }

        $command->update([
            'status' => DeviceCommandStatus::Queued,
            'command_payload' => $message,
        ]);

        $sent = $this->gateway->send($gsm, $message);

        $command->update([
            'status' => $sent ? DeviceCommandStatus::Sent : DeviceCommandStatus::Failed,
            'sent_at' => $sent ? now() : null,
        ]);
    }
}
