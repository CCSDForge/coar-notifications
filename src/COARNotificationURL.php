<?php

namespace cottagelabs\coarNotifications;

/**
 * A simple class to describe URLS for COAR Notifications.
 */
class COARNotificationURL
{
    private string $id;
    private string $mediaType;
    private array $type;

    public function __construct(string $id, string $mediaType, array $type)
    {
        $this->id = $id;
        $this->mediaType = $mediaType;
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
    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    /**
     * @param string $mediaType
     */
    public function setMediaType(string $mediaType): void
    {
        $this->mediaType = $mediaType;
    }

    /**
     * @return array
     */
    public function getType(): array
    {
        return $this->type;
    }

    /**
     * @param array $type
     */
    public function setType(array $type): void
    {
        $this->type = $type;
    }

}