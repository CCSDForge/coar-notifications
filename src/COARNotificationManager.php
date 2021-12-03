<?php

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Tools\Setup;
use Monolog\Logger;

require_once __DIR__ . '/orm/COARNotification.php';
require_once __DIR__ . '/orm/COARNotificationException.php';

// See https://rhiaro.co.uk/2017/08/diy-ldn for a very basic walkthrough of an ldn-inbox
// done by Amu Guy who wrote the spec.

/**
 *
 *
 * @throws COARNotificationException
 */
function validate_notification($notification_json) {
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

    /**
     * @throws COARNotificationException
     * @throws ORMException
     */
    public function __construct($db_conn, Logger $logger=null, string $id=null, string $inbox_url=null,
                                $accepted_formats=array('application/ld+json'),
                                $timeout=5, $user_agent='PHP Coar notify library')
    {
        if(!(is_array($db_conn) || $db_conn instanceof Connection))
            throw new COARNotificationNoDatabaseException('Either a database connection or a database configuration.');

        if(isset($logger))
            $this->logger = $logger;

        $this->id = $id ?? $_SERVER['SERVER_NAME'];
        $this->inbox_url = $inbox_url ?? $_SERVER['PHP_SELF'];

        if(!is_array($accepted_formats))
            throw new InvalidArgumentException("'accepted_formats' argument must be an array.");

        $this->accepted_formats = $accepted_formats;

        // Timeout and user agent are only relevant for outbound notifications
        $this->timeout = $timeout;
        $this->user_agent = $user_agent;

        $config = Setup::createAnnotationMetadataConfiguration(array(__DIR__."/src/orm"),
            true, null, null, false);

        $this->entityManager = EntityManager::create($db_conn, $config);

        // Verifying database connection
        try {
            $this->entityManager->getConnection()->connect();

            if(isset($this->logger))
                $this->logger->debug("Database connection verified.");
            $this->connected = true;
        } catch (Exception $e) {
            if(isset($this->logger))
                $this->logger->error("Couldn't establish a database connection: " . $e);
            return;
        }

        //$this->do_response();

    }

    /**
     * @throws COARNotificationException
     */
    public function do_response() {
        if ($_SERVER['REQUEST_METHOD'] === 'GET')   {
            http_response_code(403);
        }

        else if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        // See https://www.w3.org/TR/2017/REC-ldn-20170502/#sender
            header("Allow: " . implode(', ', ['POST', 'OPTIONS']));
            header("Accept-Post: " . implode(', ', $this->accepted_formats));

        } else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // See https://www.w3.org/TR/2017/REC-ldn-2COARTarget0170502/#sender
            if (str_starts_with($_SERVER["CONTENT_TYPE"], 'application/ld+json')) {
                // Could be followed by a 'profile' but that's not actioned on
                // https://datatracker.ietf.org/doc/html/rfc6906

                if(isset($this->logger))
                    $this->logger->debug('Received a ld+json POST request.');

                if(!$this->connected)
                    throw new COARNotificationNoDatabaseException();

                // Validating JSON and keeping the variable
                // Alternative is to load into EasyRDF, the go to rdf library for PHP,
                // or the more lightweight and ActivityStreams-specific ActivityPhp
                // This is a computationally expensive operation that should be done
                // at a later stage.

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
                    $notification->setFromId($notification_json['origin']['id']);
                    $notification->setToId($notification_json['target']['id']);

                    if($notification_json['type'])
                        $notification->setType($notification_json['type']);

                    $notification->setOriginal($notification_json);
                    $notification->setStatus(201);
                } catch (COARNotificationException | Exception $exception) {
                    if(isset($this->logger)) {
                        $this->logger->error($exception->getMessage());
                        $this->logger->debug($exception->getTraceAsString());
                    }
                    http_response_code(422);
                    return;
                }

                // Committing to database
                try {
                    $this->entityManager->persist($notification);
                    $this->entityManager->flush();
                    if(isset($this->logger))
                        $this->logger->info("Wrote inbound notification (ID: " . $notification->getId() . ") to database.");
                } catch (Exception $exception) {
                    // Trouble catching PDOExceptions
                    //if($exception->getCode() == 1062) {
                    if(isset($this->logger)) {
                        $this->logger->error($exception->getMessage());
                        $this->logger->debug($exception->getTraceAsString());
                    }

                    http_response_code(422);
                    return;
                    //}

                }

                //header("Location: " . $config['inbox_url']);
                http_response_code(201);

            } else {
                if(isset($this->logger))
                    $this->logger->debug("415 Unsupported Media Type: received a POST but content type '"
                        . $_SERVER["CONTENT_TYPE"] . "' not an accepted format.");
                http_response_code(415);
            }

        }
    }

    /**
     * @throws Exception
     */
    public function createOutboundNotification($cNActor, $cObject, $cContext, $cTarget): OutboundCOARNotification
    {
        return new OutboundCOARNotification($this->logger, $this->id, $this->inbox_url,
            $cNActor, $cObject, $cContext, $cTarget);

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
    public function announceEndorsement(OutboundCOARNotification $outboundCOARNotification, $inReplyToId = null) {
        if(!$this->connected)
            throw new COARNotificationNoDatabaseException();

        if(!empty($inReplyToId))
            $outboundCOARNotification->setInReplyToId($inReplyToId);

        $outboundCOARNotification->setType(["Announce", "coar-notify:EndorsementAction"]);

        $this->send($outboundCOARNotification);
        $this->persistOutboundNotification($outboundCOARNotification);
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
    public function announceReview(OutboundCOARNotification $outboundCOARNotification, $inReplyToId = null) {
        if(!$this->connected)
            throw new COARNotificationNoDatabaseException();

        // Special case of step 4 scenario 2
        // https://notify.coar-repositories.org/scenarios/2/4/
        if(!empty($inReplyToId))
            $outboundCOARNotification->setInReplyToId($inReplyToId);

        $outboundCOARNotification->setType(["Announce", "coar-notify:ReviewAction"]);

        $this->send($outboundCOARNotification);
        $this->persistOutboundNotification($outboundCOARNotification);
    }

    /**
     * Author requests review with possible endorsement (via repository)
     * Implements step 3 of scenario 2
     * https://notify.coar-repositories.org/scenarios/2/2/
     * @throws COARNotificationException
     */
    public function requestReview(OutboundCOARNotification $outboundCOARNotification) {
        if(!$this->connected)
            throw new COARNotificationNoDatabaseException();

        $outboundCOARNotification->setType(["Offer", "coar-notify:ReviewAction"]);

        $this->send($outboundCOARNotification);
        $this->persistOutboundNotification($outboundCOARNotification);
    }

    /**
     * todo Handle send HTTP errors
     */
    private function send(OutboundCOARNotification $outboundCOARNotification) {
        // create curl resource
        $ch = curl_init();
        $headers = [];

        // set url
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
        //curl_setopt($ch, CURLOPT_TIMEOUT, 5); //timeout in seconds
        curl_setopt($ch, CURLOPT_URL, $outboundCOARNotification->getTargetURL());
        curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/ld+json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $outboundCOARNotification->getJSON());
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
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $outboundCOARNotification->setStatus($http_code);

        if(isset($this->logger)) {
            $this->logger->info($outboundCOARNotification->getTargetURL());
            $this->logger->info($http_code);
            $this->logger->info(print_r($headers, true));
            $this->logger->info($result);
        }

        // return [$http_code, $result];

    }

    private function persistOutboundNotification($notification) {
        try {
            $this->entityManager->persist($notification);
            $this->entityManager->flush();
            $this->logger->info("Wrote outbound notification (ID: " . $notification->getId() . ") to database.");
        }
        catch (Exception $exception) {
            // Trouble catching PDOExceptions
            //if($exception->getCode() == 1062) {
            $this->logger->error($exception->getMessage());
            $this->logger->debug($exception->getTraceAsString());
            return;
            //}

        }
    }
}
