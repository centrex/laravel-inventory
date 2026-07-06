<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Enums;

enum OrderRole: string
{
    /** Standard direct sale — no agent involved. */
    case DIRECT = 'direct';

    /** Customer-facing order at B2C price; created by the agent on behalf of their customer. */
    case AGENT_B2C = 'agent_b2c';

    /** Agent-facing order at B2B price; auto-created and paired with the B2C order. */
    case AGENT_B2B = 'agent_b2b';

    /** Placed directly by a customer through the mobile app — no agent involved. */
    case USER_APP = 'user_app';

    public function label(): string
    {
        return match ($this) {
            self::DIRECT    => 'Direct',
            self::AGENT_B2C => 'Agent (Customer Invoice)',
            self::AGENT_B2B => 'Agent (Cost Order)',
            self::USER_APP  => 'Mobile App',
        };
    }

    public function isPaired(): bool
    {
        return in_array($this, [self::AGENT_B2C, self::AGENT_B2B], true);
    }
}
