<?php

declare(strict_types=1);

namespace TrackAnyDevice\Drivers\ValueObjects;

/**
 * Descriptor for a driver-specific command surfaced in the Filament UI.
 *
 * `params` is a JSON-schema-like declaration the form builder uses to
 * render the parameter inputs (`type`, `required`, `min`, `max`, `options`).
 *
 * `requiresGsm = true` means the command cannot be sent over the stream
 * channel and must be dispatched as an SMS to the device's gsm_number.
 */
readonly class AddOnCommand
{
    public function __construct(
        public string $name,
        public string $label,
        public array $params,
        public string $category,
        public bool $requiresGsm,
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'params' => $this->params,
            'category' => $this->category,
            'requires_gsm' => $this->requiresGsm,
        ];
    }
}
