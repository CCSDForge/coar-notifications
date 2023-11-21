<?php

namespace cottagelabs\coarNotifications\orm;

use cottagelabs\coarNotifications\COARNotificationActor;
use cottagelabs\coarNotifications\COARNotificationContext;
use cottagelabs\coarNotifications\COARNotificationObject;
use cottagelabs\coarNotifications\COARNotificationTarget;
use cottagelabs\coarNotifications\COARNotificationURL;
use Doctrine\ORM\Mapping as ORM;
use Exception;
use Monolog\Logger;
use stdClass;

/**
 * @ORM\Entity
 * @ORM\Table(name="notifications")
 */
class OutboundCOARNotification extends COARNotification
{

    protected object $base;
    private Logger $logger;

    public function __construct(Logger                  $logger = null, string $id, string $inbox_url,
                                COARNotificationActor   $actor, COARNotificationObject $obj,
                                COARNotificationContext $ctx = null, COARNotificationTarget $target)
    {
        parent::__construct($logger);

        if (isset($logger)) {
            $this->logger = $logger;
        }

        $this->base = new stdClass();
        $this->base->{'@context'} = ["https://www.w3.org/ns/activitystreams", "https://purl.org/coar/notify"];

        // Origin
        $this->base->origin = $this->createObject(["Service"], $id, $inbox_url);

        $this->setId();
        $this->base->id = $this->getId();

        $this->setFromId($id);
        $this->setToId($target->getId());

        $this->base->actor = $actor;

        // Object with a special character property name
        $this->base->object = $this->createNotificationObject($obj);

        if ($ctx !== null) {
            $this->base->context = $this->createNotificationObject($ctx);
        }

        $this->base->target = $target;

        $this->setOriginal($this->base);

    }

    /**
     * Create a new object with the given type, id, and inbox URL.
     *
     * @param array $type The type of the object (e.g. ["Service"]).
     * @param string $id The id of the object.
     * @param string $inbox_url The inbox URL of the object.
     * @return stdClass The created object.
     */
    private function createObject(array $type, string $id, string $inbox_url): stdClass
    {
        $object = new stdClass();
        $object->type = $type;
        $object->id = $id;
        $object->inbox = $inbox_url;

        return $object;
    }

    /**
     * Creates a notification object based on the given COARNotificationObject.
     *
     * @param COARNotificationObject $obj The COARNotificationObject to create the notification object from.
     * @return stdClass The created notification object.
     * @throws Exception If there is an error creating the notification object.
     */
    private function createNotificationObject(COARNotificationObject $obj): stdClass
    {
        $object = new stdClass();
        $object->type = $obj->getType();
        $object->id = $obj->getId();
        $object->{'ietf:cite-as'} = $obj->getIetfCiteAs();

        if ($obj->getUrl() !== null) {
            $object->url = $this->createUrlObject($obj->getUrl());
        }

        return $object;
    }


    /**
     * Creates a URL object based on a COARNotificationURL instance.
     *
     * @param COARNotificationURL $url The COARNotificationURL instance.
     * @return stdClass The URL object.
     * @throws Exception If an error occurs during the creation of the URL object.
     */
    private function createUrlObject(COARNotificationURL $url): stdClass
    {
        $urlObject = new stdClass();
        $urlObject->id = $url->getId();
        $urlObject->mediaType = $url->getMediaType();
        $urlObject->type = $url->getType();

        return $urlObject;
    }

    public function setType(array $type): void
    {
        parent::setType($type);
        $this->base->type = $type;
    }

    public function getJSON(): string
    {
        return json_encode($this->base);
    }

    public function getTargetURL(): string
    {
        return $this->base->target->getInbox();
    }

}
