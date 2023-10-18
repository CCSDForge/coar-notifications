<?php
namespace cottagelabs\coarNotifications;

use cottagelabs\coarNotifications\orm\COARNotification;
use cottagelabs\coarNotifications\orm\COARNotificationException;
use cottagelabs\coarNotifications\orm\COARNotificationNoDatabaseException;
use cottagelabs\coarNotifications\orm\OutboundCOARNotification;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Tools\Setup;
use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use Monolog\Logger;


// See https://rhiaro.co.uk/2017/08/diy-ldn for a very basic walkthrough of an ldn-inbox
// done by Amy Guy who wrote the spec.

/**
 *  This validation functions ensures that there is a '@context' property that includes
 *  ActivityStreams 2.0 and COAR Notify.
 *  Also checks for the existence of an 'id' property.
 *
 * @throws COARNotificationException
 */
function validate_notification($notification_json): void {
    // Validating that @context has ActivityStreams and Coar notify namespaces.
    if(!isset($notification_json['@context'])) {
        throw new COARNotificationException("The notification must include a '@context' property.");
    }

    if(!count(preg_grep("#^http[s]?://www.w3.org/ns/activitystreams$#", $notification_json['@context']))) {
        throw new COARNotificationException("The '@context' property must include Activity Streams 2.0 (https://www.w3.org/ns/activitystreams).");
    }

    if(!count(preg_grep("#^http[s]?://purl.org/coar/notify$#", $notification_json['@context']))) {
        throw new COARNotificationException("The '@context' property must include Notify (https://purl.org/coar/notify).");
    }

    // Validating that id must not be empty
    $mandatory_properties = ['id'];

    foreach($mandatory_properties as $mandatory_property) {
        if($notification_json[$mandatory_property] === '') {
            throw new COARNotificationException("$mandatory_property is empty.");
        }

    }
}


/**
 * A COARNotificationManager can send and receive COAR Notifications.
 * Only required parameter is either a database connection parameters or a connection.
 * @throws COARNotificationException
 * @throws ORMException
 */
class COARNotificationManager
{
    private EntityManager $entityManager;
    public Logger $logger;
    public string $id;
    public string $inbox_url;
    public int $timeout;
    public array $accepted_formats;
    public string $user_agent;
    private bool $connected = false;


    public function __construct($conn, Logger $logger=null, string $id=null, string $inbox_url=null,
                                //$accepted_formats=array('application/ld+json'),
                                $timeout=5, $user_agent='PHP COAR Notification Manager')
    {
        if(!(is_array($conn) || $conn instanceof Connection))
            throw new COARNotificationNoDatabaseException('Either a database connection or' .
                'a database configuration is required.');

        if(isset($logger))
            $this->logger = $logger;

        $this->id = $id ?? $_SERVER['SERVER_NAME'];

        if(isset($inbox_url))   {
            $this->inbox_url = $inbox_url;
        }
        else    {
            if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off')  {
                $this->inbox_url = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
            }
            else {
                $this->inbox_url = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];

            }            
            
        }

        //if(!is_array($accepted_formats))
        //    throw new InvalidArgumentException("'accepted_formats' argument must be an array.");

        $this->accepted_formats = array('application/ld+json');

        // Timeout and user agent are only relevant for outbound notifications
        $this->timeout = $timeout;
        $this->user_agent = $user_agent;

        $config = Setup::createAnnotationMetadataConfiguration(array(__DIR__."/src/orm"),
            false, null, null, false);

        if(is_array($conn))
            $conn = DriverManager::getConnection($conn, $config);            

        $this->entityManager = EntityManager::create($conn, $config);

        // Verifying database connection
        try {
            $this->entityManager->getConnection()->connect();

            if(isset($this->logger))
                $this->logger->debug("Database connection verified.");
            $this->connected = true;
        } catch (Exception $exception) {
            // Printing this exception if logger is not available as this is a fatal
            // initialisation error
            if(isset($this->logger))
                $this->logger->error("Couldn't establish a database connection: " . $exception);
            else
                print("Couldn't establish a database connection: " . $exception);
            return;
        }

    }

    /**
     * Gets all COARNotifications sorted by timestamp descending.
     * 
     * Note that this uses Doctrine Query Language, DQL see: 
     * https://www.doctrine-project.org/projects/doctrine-orm/en/2.6/reference/dql-doctrine-query-language.html
     * 
     * Doctrine supports pagination, see:
     * https://www.doctrine-project.org/projects/doctrine-orm/en/2.6/tutorials/pagination.html
     * 
     * @param string $direction, 'in', 'out', or default is all
     * @param string $select columns to select, default is all
     * @return array results of query as an array
     */
    
    public function getNotifications(string $direction=NULL, string $select='c'): array    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        $query = $queryBuilder
            ->select($select)
            ->from('cottagelabs\coarNotifications\orm\COARNotification', 'c');
        
        if($direction == 'in')  {
            $query->where('c NOT INSTANCE OF cottagelabs\coarNotifications\orm\OutboundCOARNotification');
        }
        else if($direction == 'out')    {
            $query->where('c INSTANCE OF cottagelabs\coarNotifications\orm\OutboundCOARNotification');
        }
        
        $query->orderBy('c.timestamp', 'DESC')
            ->setMaxResults(1000);
    
        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * A wrapper around the preceding method getNotifications that only passes a string
     * argument to it asking for the 'id' column only.
     * 
     * The results are ordered by timestamp descending.
     * 
     * @return array an array of UUIDs of notifications
     */
    public function getReceivedNotificationIds(): array {
        return array_column($this->getNotifications('in', 'c.id'), 'id');
    }

    /**
     * Sets headers to disclose RDF content types accepted by the server.
     * 
     * Should be called in response to a options request.
     * 
     * See: https://www.w3.org/TR/2017/REC-ldn-20170502/#sender
     */
    public function setOptionsResponseHeaders(): void {
        header("Allow: " . implode(', ', ['POST', 'OPTIONS']));
        header("Accept-Post: " . implode(', ', $this->accepted_formats));

    }

    /**
     * Handles incoming post requests, validates the payload and persists the notification
     * in a database.
     * 
     * Accordingly sets one of the following response codes:
     * *  400 if JSON is malformed
     * *  422 if notification is not valid or an error occurs when persisting the notification
     * *  201 if notification is successfully received and persisted
     * *  415 if an unsupported media type is used
     */
    public function getPostResponse(): void {
        // See https://www.w3.org/TR/2017/REC-ldn-2COARTarget0170502/#sender
        if (str_starts_with($_SERVER["CONTENT_TYPE"], 'application/ld+json')) {
            // Could be followed by a 'profile' but that's not actioned on
            // https://datatracker.ietf.org/doc/html/rfc6906

            if(isset($this->logger))
                $this->logger->debug('Received a ld+json POST request.');

            if(!$this->connected)
                throw new COARNotificationNoDatabaseException();

            
            // Validating JSON
            try {
                $notification_json = json_decode(
                    file_get_contents('php://input'), true, 512,
                    JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                if(isset($this->logger)) {
                    $this->logger->error("Syntax error: Badly formed JSON in payload.");
                    $this->logger->debug($exception->getTraceAsString());
                }
                http_response_code(400);
                return;
            }

            try {
                validate_notification($notification_json);
            } catch (COARNotificationException $exception) {
                if(isset($this->logger)) {
                    $this->logger->error("Invalid notification: " . $exception->getMessage());
                    $this->logger->debug($exception->getTraceAsString());
                    $this->logger->debug((print_r($notification_json, true)));
                }
                http_response_code(422);
                return;
            }

            // Creating an inbound ORM object
            try {
                $notification = new COARNotification($this->logger);
                $notification->setId($notification_json['id'] ?? '');
                $notification->setFromId($notification_json['origin']['id'] ?? '');
                $notification->setToId($notification_json['target']['id'] ?? '');

                if($notification_json['type'])
                    $notification->setType($notification_json['type']);

                $notification->setOriginal($notification_json);
                $notification->setStatus(201);
            } catch (COARNotificationException | Exception $exception) {
                $this->log_error($exception);
                http_response_code(422);
                return;
            }

            // Committing to database
            $this->persistNotification($notification);

            //header("Location: " . $config['inbox_url']);
            http_response_code(201);

        } else {
            if(isset($this->logger))
                $this->logger->debug("415 Unsupported Media Type: received a POST but content type '"
                    . $_SERVER["CONTENT_TYPE"] . "' not an accepted format.");
            http_response_code(415);
        }
        
    }

    /**
     * Handles incoming get requests to the inbox url.
     * 
     * See: https://www.w3.org/TR/2017/REC-ldn-20170502/#receiving-inbox-contents
     *  
     * @return string JSON object with an array of notifications
     */
    public function getGetResponse(): string {
        header('Content-Type: application/ld+json; charset=utf-8');
        header('Accept: ' . implode(', ', $this->accepted_formats));

        $notifications = array('@context' => 'http://www.w3.org/ns/ldp',
            '@id' => $this->id,
            'contains' => array_map(fn($id): string => $this->inbox_url . '/' . substr($id, 9), $this->getReceivedNotificationIds()));

        return json_encode($notifications);

    }

    /**
     * Wrapper to create and return an outbound COARNotification
     *  
     * @param COARNotificationActor $actor 
     * @param COARNotificationObject $obj 
     * @param COARNotificationContext $ctx
     * @param COARNotificationTarget $target
     * 
     * @throws Exception
     * @return OutBoundCOARNotification
     */
    public function createOutboundNotification($actor, $obj, $ctx, $target): OutboundCOARNotification
    {
        if(isset($logger))
            return new OutboundCOARNotification($this->logger, $this->id, $this->inbox_url, $actor, $obj, $ctx, $target);
        
        return new OutboundCOARNotification(null, $this->id, $this->inbox_url, $actor, $obj, $ctx, $target);
    
    }

    /**
     * Utility function to process outbound notifications after a pattern has been applied.
     */
    private function doPattern(OutboundCOARNotification $outboundCOARNotification, $inReplyToId = null): void   {
        if(!empty($inReplyToId))
            $outboundCOARNotification->setInReplyToId($inReplyToId);
        
        $this->send($outboundCOARNotification);
        $this->persistNotification($outboundCOARNotification);
    }

    /**
     * This pattern is used to acknowledge and accept a request (offer).
     * 
     * https://notify.coar-repositories.org/patterns/acknowledge-acceptance/
     * @param OutboundCOARNotification $outboundCOARNotification
     * @param null $inReplyToId
     * @throws COARNotificationException
     * @throws COARNotificationNoDatabaseException
     */
    public function acknowledgeAndAccept(OutboundCOARNotification $outboundCOARNotification, $inReplyToId = null): void {
        $outboundCOARNotification->setType(["Accept"]);

        $this->doPattern($outboundCOARNotification, $inReplyToId);
    }

    /**
     * This pattern is used to acknowledge and reject a request (offer).
     * 
     * https://notify.coar-repositories.org/patterns/acknowledge-rejection/
     * @param OutboundCOARNotification $outboundCOARNotification
     * @param null $inReplyToId
     * @throws COARNotificationException
     * @throws COARNotificationNoDatabaseException
     */
    public function acknowledgeAndReject(OutboundCOARNotification $outboundCOARNotification, $inReplyToId = null): void {
        $outboundCOARNotification->setType(["Reject"]);

        $this->doPattern($outboundCOARNotification, $inReplyToId);
    }

    /**
     * Author requests review with possible endorsement (via overlay journal)
     * Implements step 3 of scenario 1
     * https://notify.coar-repositories.org/scenarios/1/5/
     * @param OutboundCOARNotification $outboundCOARNotification
     * @param null $inReplyToId
     * @throws COARNotificationException
     * @throws COARNotificationNoDatabaseException
     */
    public function announceEndorsement(OutboundCOARNotification $outboundCOARNotification, $inReplyToId = null): void {
        $outboundCOARNotification->setType(["Announce", "coar-notify:EndorsementAction"]);

        $this->doPattern($outboundCOARNotification, $inReplyToId);
    }

    /**
     * This pattern is used to announce a resource has been ingested.
     * 
     * https://notify.coar-repositories.org/patterns/announce-ingest/
     * @param OutboundCOARNotification $outboundCOARNotification
     * @param null $inReplyToId
     * @throws COARNotificationException
     * @throws COARNotificationNoDatabaseException
     */
    public function announceIngest(OutboundCOARNotification $outboundCOARNotification, $inReplyToId = null): void {
        $outboundCOARNotification->setType(["Announce", "coar-notify:IngestAction"]);

        $this->doPattern($outboundCOARNotification, $inReplyToId);
    }

    /**
     * This pattern is used to announce a relationship between two resources.
     * 
     * https://notify.coar-repositories.org/patterns/announce-relationship/
     * @param OutboundCOARNotification $outboundCOARNotification
     * @param null $inReplyToId
     * @throws COARNotificationException
     * @throws COARNotificationNoDatabaseException
     */
    public function announceRelationship(OutboundCOARNotification $outboundCOARNotification, $inReplyToId = null): void {
        $outboundCOARNotification->setType(["Announce", "coar-notify:RelationshipAction"]);

        $this->doPattern($outboundCOARNotification, $inReplyToId);
    }

    /**
     * Author requests review with possible endorsement (via overlay journal)
     * Implements step 3 of scenario 1
     * https://notify.coar-repositories.org/scenarios/1/3/
     * @param OutboundCOARNotification $outboundCOARNotification
     * @param null $inReplyToId
     * @throws COARNotificationException
     * @throws COARNotificationNoDatabaseException
     */
    public function announceReview(OutboundCOARNotification $outboundCOARNotification, $inReplyToId = null): void {
        $outboundCOARNotification->setType(["Announce", "coar-notify:ReviewAction"]);

        $this->doPattern($outboundCOARNotification, $inReplyToId);
    }

    /**
     * This pattern is used to request endorsement of a resource owned by the origin system.
     * https://notify.coar-repositories.org/patterns/request-endorsement/
     * @param OutboundCOARNotification $outboundCOARNotification
     * @throws COARNotificationException
     */
    public function requestEndorsement(OutboundCOARNotification $outboundCOARNotification, $inReplyToId = null): void {
        $outboundCOARNotification->setType(["Offer", "coar-notify:EndorsementAction"]);

        $this->doPattern($outboundCOARNotification, $inReplyToId);
    }

    /**
     * This pattern is used to request that the target system ingest a resource.
     * https://notify.coar-repositories.org/patterns/request-ingest/
     * @param OutboundCOARNotification $outboundCOARNotification
     * @throws COARNotificationException
     */
    public function requestIngest(OutboundCOARNotification $outboundCOARNotification, $inReplyToId = null): void {
        $outboundCOARNotification->setType(["Offer", "coar-notify:IngestAction"]);

        $this->doPattern($outboundCOARNotification, $inReplyToId);
    }

    /**
     * Author requests review with possible endorsement (via repository)
     * Implements step 3 of scenario 2
     * https://notify.coar-repositories.org/scenarios/2/2/
     * @param OutboundCOARNotification $outboundCOARNotification
     * @throws COARNotificationException
     */
    public function requestReview(OutboundCOARNotification $outboundCOARNotification, $inReplyToId = null): void {
        $outboundCOARNotification->setType(["Offer", "coar-notify:ReviewAction"]);

        $this->doPattern($outboundCOARNotification, $inReplyToId);
    }

    /**
     * This pattern is used to retract (undo) an offer previously made.
     * https://notify.coar-repositories.org/patterns/undo-offer/
     * @param OutboundCOARNotification $outboundCOARNotification
     * @throws COARNotificationException
     */
    public function retractOffer(OutboundCOARNotification $outboundCOARNotification, $inReplyToId = null): void {    
        $outboundCOARNotification->setType(["Undo"]);

        $this->doPattern($outboundCOARNotification, $inReplyToId);
    }

    /**
     * This method uses Guzzle to send an outbound COAR Notification to a URL.
     * 
     * 400-500 HTTP errors will raise and error, can be disabled by ['http_errors' => false] in Guzzle client initiation
     * 
     * @param OutboundCOARNotification $outboundCOARNotification
     */
    private function send(OutboundCOARNotification $outboundCOARNotification): void {
        $this->log_info(sprintf("Sending notification (ID: %s) to %s", $outboundCOARNotification->getId(), $outboundCOARNotification->getTargetURL()));

        $client = new \GuzzleHttp\Client();

        try {
            $res = $client->request('POST', $outboundCOARNotification->getTargetURL(), [
                'timeout' => $this->timeout,
                'headers' => ['Content-Type' => 'application/ld+json', 'User-Agent' => $this->user_agent],
                'body' => $outboundCOARNotification->getJSON(),
            ]);

            $outboundCOARNotification->setStatus($res->getStatusCode());
        }
        catch (ConnectException | RequestException $exception) {
            // Guzzle will use cURL by default, we can therefore expect a ContextHandler with
            // cURL error codes
            $ctx = $exception->getHandlerContext();

            if(in_array('errno', $ctx)) {
                $error_no = $ctx['errno'];
                $outboundCOARNotification->setStatus($error_no);

                $msg = "Outbound notification (ID: " . $outboundCOARNotification->getId() . ") could not be sent.";
                // Timed out?
                if($error_no === 7)
                    $msg .= " Couldn't connect to " . $outboundCOARNotification->getTargetURL();
                else if($error_no === 28)
                    $msg .= " Timed out.";
                else
                    $msg .= " cURL error no: " . $error_no;

                if(isset($this->logger))
                    $this->logger->error($msg);

            }

            $this->log_error($exception);

        }
        catch (Exception $exception) {
            $this->log_error($exception);

        }

    }

    /**
     * This method saves both incoming and outgoing COAR Notifications to the database using Doctrine's entity manager.
     * 
     * @param COARNotification $notification
     */
    private function persistNotification(COARNotification $notification): void {
        try {
            $this->entityManager->persist($notification);
            $this->entityManager->flush();

            if(isset($this->logger)) {
                $msg = 'Wrote ';

                if(is_null($notification->getStatus()) ||
                    ($notification->getStatus() < 200 || $notification->getStatus() > 299)) {
                    $msg .= "failed ";

                    if(!is_null($notification->getStatus()))
                        $msg .= "(" . $notification->getStatus() . ") ";
                }

                if ($notification instanceof OutboundCOARNotification)
                    $msg .= "outbound";
                else
                    $msg .= "inbound";

                $this->logger->info(sprintf("%s notification (ID: %s) to database.", $msg, $notification->getId()));
            }
        }
        catch (Exception $exception) {
            $this->log_error($exception);

            // If an inbound notification can't be written to database
            // then we issue a 422
            if(get_class($notification) != "OutboundCOARNotification")
                http_response_code(422);

        }
    }

    /**
     * Gets and returns a COAR Notification from the database by id.
     * 
     * @param string $notificationId
     * @return COARNotification
     */
    public function getNotificationById(string $notificationId): ?COARNotification
    {
        return $this->entityManager->getReference(COARNotification::class, $notificationId);
        
    }

    /**
     * Removes a COAR Notification by id.
     * 
     *  @param string $notificationId
     */
    public function removeNotificationById(string $notificationId): void
    {

        $notificationToRemove = $this->getNotificationById($notificationId);

        try {
            $this->entityManager->remove($notificationToRemove);
            $this->entityManager->flush();
            $this->log_info(sprintf("Removing notification (ID: %s) from database.", $notificationId));
 
        } catch (Exception $exception) {
            $this->log_error($exception);
        }
    }

    public function __toString(): string {
        return static::class . $this->id;
    }

    /**
     * Utility method to log errors
     * 
     * @param Exception $exception
     * @param bool $trace
     */
    private function log_error(?Exception $exception, bool $trace=true): void {
        if (isset($this->logger)) {
            $this->logger->error($exception->getMessage());

            if($trace)  {
                $this->logger->debug($exception->getTraceAsString());
            }
        }
    }

    /**
     * Utility method to log info
     * 
     * @param string $msg
     */
    private function log_info(string $msg): void {
        if (isset($this->logger)) {
            $this->logger->info($msg);
        }
    }

}
