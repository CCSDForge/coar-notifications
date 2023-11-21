<?php

namespace cottagelabs\coarNotifications;

use JsonSerializable;
use stdClass;

/**
 *  This identifies the party or process that initiated the activity.
 *
 *  See: https://notify.coar-repositories.org/patterns/
 */
class COARNotificationActor implements JsonSerializable
{
    private string $id;
    private string $name;
    private string $type;

    public function __construct(string $id, string $name, string $type)
    {
        $this->id = $id;
        $this->name = $name;
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
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
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