<?php

namespace cottagelabs\coarNotifications\orm;

use Doctrine\ORM\Mapping as ORM;
use Exception;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;

// Not exhaustive
// This list has been transformed to lower-case
// ActivityStreams 2.0 Activity Types
// see https://www.w3.org/TR/activitystreams-vocabulary/#activity-types
const ACTIVITIES = array('accept', 'add', 'announce', 'arrive', 'block', 'create', 'delete', 'dislike', 'flag',
    'follow', 'ignore', 'invite', 'join', 'leave', 'like', 'listen', 'move', 'offer', 'question', 'reject', 'read',
    'remove', 'tentativereject', 'tentativeaccept', 'travel', 'undo', 'update', 'view');


/**
 * @author Hrafn Malmquist - Cottage Labs - hrafn@cottagelabs.com
 * @ORM\Entity
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorColumn(name="direction", type="string")
 * @ORM\DiscriminatorMap({"INBOUND" = "COARNotification", "OUTBOUND" = "OutboundCOARNotification"})
 * @ORM\Table(name="notifications")
 */
class COARNotification
{

    private Logger $logger;

    /**
     * @ ORM\Id
     * @ ORM\Column(type="integer")
     * @ ORM\GeneratedValue
     */
    // private $id;
    /**
     * @ORM\Id
     * @ORM\Column(type="string", unique=true)
     */
    private string $id;

    /**
     * @ORM\Column(type="string", nullable=false)
     */
    private string $fromId;

    /**
     * @ORM\Column(type="string", nullable=false)
     */
    private string $toId;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private string $inReplyToId;

    /**
     * @ORM\Column(type="string", nullable=false)
     */
    private string $type;

    /**
     * This value can either be 100 <, HTTP Code or < 100, a curl error code, assuming
     * Guzzle is using the default HTTP handler, curl.     *
     *
     * See:
     * https://curl.se/libcurl/c/libcurl-errors.html
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $status = null;

    /**
     * @ORM\Column(type="datetime", nullable=false)
     * @ORM\Version
     */
    private $timestamp;

    /**
     * @ORM\Column(type="json")
     */
    private string $original;

    /**
     */
    public function __construct(Logger $logger = null)
    {
        if (isset($logger))
            $this->logger = $logger;

    }

    /**
     * @return string
     */
    public function getOriginal(): string
    {
        return $this->original;
    }

    /**
     * @param mixed $original
     */
    public function setOriginal($original): void
    {
        $this->original = json_encode($original);
    }

    /**
     * @return int
     */
    public function getStatus(): ?int
    {
        return $this->status;
    }

    /**
     * @param mixed $status
     */
    public function setStatus($status): void
    {
        $this->status = $status;
    }

    /**
     * @param mixed $inReplyToId
     */
    public function setInReplyToId(string $inReplyToId): void
    {
        $this->inReplyToId = $inReplyToId;
    }

    /**
     * @return string
     */
    public function getFromId(): string
    {
        return $this->fromId;
    }

    /**
     * @param string $fromId
     */
    public function setFromId(string $fromId): void
    {
        $this->fromId = $fromId;
    }

    /**
     * @return string
     */
    public function getToId(): string
    {
        return $this->toId;
    }

    /**
     * @param string $toId
     */
    public function setToId(string $toId): void
    {
        $this->toId = $toId;
    }

    /**
     * This should include one of the Activity Stream 2.0 Activity Types.
     * https://www.w3.org/TR/activitystreams-vocabulary/
     * It may (depending on the activity) also include a type from the Notify Activity Types vocabulary
     * https://purl.org/coar/notify_vocabulary/
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param array $type
     * @throws COARNotificationException
     */
    public function setType(array $type): void
    {
        $this->validateType($type);
        $this->type = json_encode($type);
    }

    /**
     * A notification can be of more than one type and at least one must be an Activity Stream 2.0 Activity Type .
     * @throws COARNotificationException
     */
    protected function validateType(array $type): void
    {
        if (count(array_intersect(array_map('strtolower', $type), ACTIVITIES)) > 0) {
            if (isset($this->logger))
                $this->logger->warning("(UId: '" . $this->getId() . "')"
                    . "Does not have an Activity Stream 2.0 Activity Type.");

        }
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        if (empty($this->id))
            return "";

        return $this->id;
    }

    /**
     * Called with parameter when notification is inbound.
     * Called without parameter when notification is outbound.
     * @param string|null $id
     * @throws Exception
     */
    public function setId(string $id = null): void
    {
        if (empty($this->id) && empty($id)) {
            $id = "urn:uuid:" . Uuid::uuid4()->serialize();
        }

        $this->validateId($id);

        $this->id = $id;
    }

    /**
     * Validates $id argument passed to Notification constructor.
     * It's recommended to be URN:UUID, an HTTP URI may be used.
     * It is checked for being either UUID4 or a valid URL.
     * @param $id
     * @throws COARNotificationException
     */
    private function validateId($id): void
    {
        // Only condition that can be considered invalid $id
        if ($id === "") {
            if (isset($this->logger))
                $this->logger->error('UId can not be null.');
            throw new COARNotificationException('UId can not be null.');
        }

        $pattern = '/^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

        if (!filter_var($id, FILTER_VALIDATE_URL) && (preg_match($pattern, $id) === 0)) {
            if (isset($this->logger))
                $this->logger->warning("(UId: '$id') Uid is neither a valid URL nor an UUID.");
        }


    }

    public function __toString(): string
    {
        return $this->getId();
    }

    /**
     * @return mixed
     */
    public function getTimestamp()
    {
        return $this->timestamp;
    }

    public function getInReplyTo(): string
    {
        return $this->inReplyToId;
    }

}
