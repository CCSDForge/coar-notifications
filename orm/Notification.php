<?php

use Doctrine\ORM\Mapping as ORM;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;

require_once 'NotificationException.php';
require_once __DIR__ . "/../src/objects.php";
//namespace cottagelabs/php-coar-notifications;

$config = include(__DIR__ . '/../config.php');

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
 * @ORM\DiscriminatorMap({"INBOUND" = "Notification", "OUTBOUND" = "OutboundNotification"})
 * @ORM\Table(name="notifications")
 */
class Notification {

    // create a log channel
    private Logger $log;

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
     * @ORM\Column(type="string")
     */
    private string $inReplyToId;

    /**
     * @ORM\Column(type="string", nullable=false)
     */
    private string $type;

    /**
     * @ORM\Column(type="integer")
     */
    private $status;

    /**
     * @ORM\Column(type="datetime", nullable=false)
     * @ORM\Version
     */
     private $timestamp;

    /**
     * @ORM\Column(type="json")
     */
     private $original;

    /**
     */
    public function __construct()
    {
        global $config;
        $this->log = $config['log'];

    }

    /**
     * @return mixed
     */
    public function getOriginal()
    {
        return $this->original;
    }

    /**
     * @param mixed $original
     */
    public function setOriginal($original): void
    {
        $this->original = $original;
    }

    /**
     * Called with parameter when notification is inbound.
     * Called without parameter when notification is outbound.
     * @param string|null $id
     * @throws Exception
     */
    public function setId(string $id = null): void
    {
        if(empty($this->id) && empty($id)) {
            $id = "urn:uuid:" . Uuid::uuid4()->serialize();
        }

        $this->id = $id;
    }

    /**
     * @return mixed
     */
    public function getStatus()
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
     * @return mixed
     */
    public function getInReplyToId()
    {
        return $this->inReplyToId;
    }

    /**
     * @param mixed $inReplyToId
     */
    public function setInReplyToId($inReplyToId): void
    {
        $this->inReplyToId = $inReplyToId;
    }

    /**
     * Validates $id argument passed to Notification constructor.
     * It's recommended to be URN:UUID, an HTTP URI may be used.
     * It is checked for being either UUID4 or a valid URL.
     * @param $id
     * @throws NotificationException
     */
    private function validateId($id):void {
        // Only condition that can be considered invalid $id
        if($id === "") {
            throw new NotificationException('UId can not be null.');
        }
        elseif (!filter_var($id, FILTER_VALIDATE_URL) &&
            (preg_match('/^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id) === 0)) {
            $this->log->warning("(UId: '$id') Uid is neither a valid URL nor an UUID.");
        }


    }

    /**
     * @throws NotificationException
     */
    private function validateType($type):void {
        if($type === "")
            throw new NotificationException("Type can not be null.");
        else if(count(array_diff(array_map('strtolower', json_decode($type)), ACTIVITIES)) === count(json_decode($type))) {
            //!in_array(strtolower($type), ACTIVITIES)) {
            $this->log->warning("(UId: '" . $this->getId() . "') Type '$type' is not an Activity Stream 2.0 Activity Type.");

        }
        //$this->log->info(print_r(array_map('strtolower', json_decode($type)), true));
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        if(empty($this->id))
            return "";

        return $this->id;
    }

    /**
     * This should include one of the Activity Stream 2.0 Activity Types.
     * https://www.w3.org/TR/activitystreams-vocabulary/
     * It may (depending on the activity) also include a type from the Notify Activity Types vocabulary
     * https://notify.coar-repositories.org/vocabularies/activity_types/ (404)
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param string $type
     * @throws NotificationException
     */
    public function setType(string $type): void
    {
        $this->validateType($type);
        $this->type = $type;
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

    /**
     * @param mixed $timestamp
     */
    public function setTimestamp($timestamp): void
    {
        $this->timestamp = $timestamp;
    }

}

/**
 * @ORM\Entity
 * @ORM\Table(name="notifications")
 */
class OutboundNotification extends Notification {

    private object $base;
    private Logger $log;

    public function __construct()
    {
        global $config;
        parent::__construct();
        $this->log = $config['log'];
        $this->base = new stdClass();
        $this->base->{'@context'} = ["https://www.w3.org/ns/activitystreams", "http://purl.org/coar/notify"];

        // Actor
        $this->base->actor = new stdClass();

        // Context
        $this->base->context = new stdClass();

        // Object
        $this->base->object = new stdClass();

        // Origin
        $this->base->origin = new stdClass();
        $this->base->origin->type = ["Service"];
        $this->base->origin->id = $config['id'];
        $this->base->origin->inbox = $config['inbox_url'];

        // Target
        $this->base->target = new stdClass();
        $this->base->target->type = ["Service"];

        // Context
        $this->base->context = new stdClass();
        $this->base->context->type = "sorg:AboutPage";

    }

    public function setGenericProperties(COARActor $cActor, COARObject $cObject,
                                         COARContext $cContext, COARTarget $cTarget) {
        $this->setId();
        $this->base->id = $this->getId();

        $this->base->actor = $cActor;

        // Object with a special character property name
        $this->base->object->type = $cObject->getType();
        $this->base->object->id = $cObject->getId();
        $this->base->object->{'ietf:cite-as'} = $cObject->getIetfCiteAs();

        // Context and child URL object both with special character property name
        $this->base->context->id = $cContext->getId();
        $this->base->context->{'ietf:cite-as'} = $cContext->getIetfCiteAs();
        $this->base->context->url = new stdClass();
        $this->base->context->url->id = $cContext->getUrl()->getId();
        $this->base->context->url->{"media-type"} = $cContext->getUrl()->getMediaType();
        $this->base->context->url->type =  $cContext->getUrl()->getType();

        $this->base->target = $cTarget;

    }

    /**
     * Author requests review with possible endorsement (via overlay journal)
     * Implements step 3 of scenario 1
     * https://notify.coar-repositories.org/scenarios/1/3/
     * @return string
     */
    public function announceReview(string $actorId, string $actorName, string $actorType,
                                   string $articleId, string $articleCite,
                                   string $reviewId, string $reviewCite, array $reviewType,
                                   string $targetId, string $targetInbox, string $inReplyTo = null): array {
        $this->setGenericProperties();

        // Special case of step 4 scenario 2
        // https://notify.coar-repositories.org/scenarios/2/4/
        if(!empty($inReplyTo)) {
            $this->base->inReplyTo = $inReplyTo;
            $this->setInReplyToId($inReplyTo);
        }

        // Actor
        $this->base->actor->id = $actorId;
        $this->base->actor->name = $actorName;
        $this->base->actor->type = $actorType;

        // Object
        $this->base->object->type = $reviewType;
        $this->base->object->id = $reviewId;
        $this->base->object->{'ietf:cite-as'} = $reviewCite;

        $this->base->type = ["Announce", "coar-notify:ReviewAction"];
        $this->setType(json_encode($this->base->type));
        // Context
        $this->base->context->id = $articleId;
        $this->base->context->{'ietf:cite-as'} = $articleCite;
        $this->base->context->url->type =  ["Article", "sorg:ScholarlyArticle"];
        // Target
        $this->base->target->id = $targetId;
        $this->base->target->inbox = $targetInbox;

        $this->setOriginal(json_encode($this->base));
        return $this->send();
    }

    // scenarios 1, 2, 3, 4 and 9,

    /**
     * Author requests review with possible endorsement (via overlay journal)
     * Implements step 3 of scenario 1
     * https://notify.coar-repositories.org/scenarios/1/5/
     * @param string $articleId
     * @param string $articleCite
     * @param string $reviewId
     * @param string $reviewCite
     * @param array $reviewType
     * @param string $targetId
     * @param string $targetInbox
     * @return array
     * @throws NotificationException
     */
    public function announceEndorsement(COARActor $cActor, COARContext $cContext, COARObject $cObject,
                                        COARTarget $cTarget, $inReplyTo = null): array {
        $this->setGenericProperties($cActor, $cObject, $cContext, $cTarget);

        // Special case of step 6 scenario 2
        // https://notify.coar-repositories.org/scenarios/2/4/
        if(!empty($inReplyTo)) {
            $this->base->inReplyTo = $inReplyTo;
            $this->setInReplyToId($inReplyTo);
        }

        $this->base->type = ["Announce", "coar-notify:EndorsementAction"];
        $this->setType(json_encode($this->base->type));

        $this->setOriginal(json_encode($this->base));
        return $this->send();
    }


    /**
     * Author requests review with possible endorsement (via repository)
     * Implements step 3 of scenario 2
     * https://notify.coar-repositories.org/scenarios/2/2/
     * @param string $actorId
     * @param string $actorName
     * @param string $articleId
     * @param string $articleCite
     * @param string $reviewId
     * @param string $reviewCite
     * @param array $reviewType
     * @param string $targetId
     * @param string $targetInbox
     * @throws NotificationException
     */
    public function requestReview(string $actorId, string $actorName,
                                  string $articleId, string $articleCite,
                                  string $reviewId, string $reviewCite, array $reviewType,
                                  string $targetId, string $targetInbox): array {
        $this->setGenericProperties();

        // Actor
        $this->base->actor->id = $actorId;
        $this->base->actor->name = $actorName;
        $this->base->actor->type = "Person";
        // Object
        $this->base->object->type = $reviewType;
        $this->base->object->id = $reviewId;
        $this->base->object->{'ietf:cite-as'} = $reviewCite;

        $this->base->type = ["Offer", "coar-notify:ReviewAction"];
        $this->setType(json_encode($this->base->type));
        // Context
        $this->base->context->id = $articleId;
        $this->base->context->{'ietf:cite-as'} = $articleCite;
        $this->base->context->url->type =  ["Article", "sorg:ScholarlyArticle"];
        // Target
        $this->base->target->id = $targetId;
        $this->base->target->inbox = $targetInbox;

        $this->setOriginal(json_encode($this->base));
        return $this->send();
    }

    /**
     * todo Handle send HTTP errors
     */
    public function send(): array {
        global $config;

        // create curl resource
        $ch = curl_init();
        $headers = [];

        // set url
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $config['connect_timeout']);
        //curl_setopt($ch, CURLOPT_TIMEOUT, 5); //timeout in seconds
        curl_setopt($ch, CURLOPT_URL, $this->base->target->getInbox());
        curl_setopt($ch, CURLOPT_USERAGENT, $config['user_agent']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/ld+json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($this->base));
        curl_setopt($ch, CURLOPT_HEADERFUNCTION,
            function($curl, $header) use (&$headers)
            {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) // ignore invalid headers
                    return $len;

                $headers[strtolower(trim($header[0]))][] = trim($header[1]);

                return $len;
            }
        );

        // Send request.
        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->setStatus($httpcode);

        $this->log->info($this->base->target->getInbox());
        $this->log->info($httpcode);
        $this->log->info(print_r($headers, true));
        $this->log->info($result);

        return [$httpcode, $result];

    }

}