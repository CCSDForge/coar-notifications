<?php

namespace cottagelabs\coarNotifications;

use JsonSerializable;
use stdClass;

/**
 *  The intended destination of the activity, typically the service which consumes the notification.
 *
 *  See: https://notify.coar-repositories.org/patterns/
 */
class COARNotificationTarget implements JsonSerializable
{
    private string $id;
    private string $inbox;
    private string $type;

    public function __construct(string $id, string $inbox, string $type = "Service")
    {
        $this->id = $id;
        $this->inbox = $inbox;
        $this->type = $type;

    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @param string $id
     */
    public function setId(string $id): void
    {
        $this->id = $id;
    }

    /**
     * @return string
     */
    public function getInbox(): string
    {
        return $this->inbox;
    }

    /**
     * @param string $inbox
     */
    public function setInbox(string $inbox): void
    {
        $this->inbox = $inbox;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param string $type
     */
    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function jsonSerialize(): stdClass
    {
        $json = new stdClass();

        foreach ($this as $key => $value) {
            $json->$key = $value;
        }
        return $json;
    }
}