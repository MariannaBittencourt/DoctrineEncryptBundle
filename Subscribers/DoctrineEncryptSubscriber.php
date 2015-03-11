<?php

namespace Ambta\DoctrineEncryptBundle\Subscribers;

use Doctrine\ORM\Events;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Common\Annotations\Reader;
use \Doctrine\ORM\EntityManager;
use \ReflectionClass;
use Ambta\DoctrineEncryptBundle\Encryptors\EncryptorInterface;
use Symfony\Component\Security\Core\Util\ClassUtils;

/**
 * Doctrine event subscriber which encrypt/decrypt entities
 */
class DoctrineEncryptSubscriber implements EventSubscriber {
    /**
     * Encryptor interface namespace
     */

    const ENCRYPTOR_INTERFACE_NS = 'Ambta\DoctrineEncryptBundle\Encryptors\EncryptorInterface';

    /**
     * Encrypted annotation full name
     */
    const ENCRYPTED_ANN_NAME = 'Ambta\DoctrineEncryptBundle\Configuration\Encrypted';

    /**
     * Encryptor
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * Annotation reader
     * @var \Doctrine\Common\Annotations\Reader
     */
    private $annReader;

    /**
     * Register to avoid multi decode operations for one entity
     * @var array
     */
    private $decodedRegistry = array();

    /**
     * Initialization of subscriber
     * @param Reader $annReader
     * @param string $encryptorClass  The encryptor class.  This can be empty if
     * a service is being provided.
     * @param string $secretKey The secret key.
     * @param EncryptorInterface|NULL $service (Optional)  An EncryptorInterface.
     * This allows for the use of dependency injection for the encrypters.
     */
    public function __construct(Reader $annReader, $encryptorClass, $secretKey, EncryptorInterface $service = NULL) {
        $this->annReader = $annReader;
        if ($service instanceof EncryptorInterface) {
            $this->encryptor = $service;
        } else {
            $this->encryptor = $this->encryptorFactory($encryptorClass, $secretKey);
        }
    }

    /**
     * Listen a postUpdate lifecycle event. Checking and encrypt entities
     * which have @Encrypted annotation
     * @param LifecycleEventArgs $args
     */
    public function postUpdate(LifecycleEventArgs $args) {

        $entity = $args->getEntity();
        $this->processFields($entity, false);

    }

    /**
     * Listen a preUpdate lifecycle event. Checking and encrypt entities fields
     * which have @Encrypted annotation. Using changesets to avoid preUpdate event
     * restrictions
     * @param PreUpdateEventArgs $args
     */
    public function preUpdate(PreUpdateEventArgs $args) {

        $entity = $args->getEntity();
        $this->processFields($entity);

    }

    /**
     * Listen a postLoad lifecycle event. Checking and decrypt entities
     * which have @Encrypted annotations
     * @param LifecycleEventArgs $args
     */
    public function postLoad(LifecycleEventArgs $args) {

        $entity = $args->getEntity();
        $this->processFields($entity, false);

    }

    /**
     * Realization of EventSubscriber interface method.
     * @return Array Return all events which this subscriber is listening
     */
    public function getSubscribedEvents() {
        return array(
            Events::postUpdate,
            Events::preUpdate,
            Events::postLoad,
        );
    }

    /**
     * Capitalize string
     * @param string $word
     * @return string
     */
    public static function capitalize($word) {
        if (is_array($word)) {
            $word = $word[0];
        }

        return str_replace(' ', '', ucwords(str_replace(array('-', '_'), ' ', $word)));
    }

    /**
     * Process (encrypt/decrypt) entities fields
     * @param Obj $entity Some doctrine entity
     * @param Boolean $isEncryptOperation If true - encrypt, false - decrypt entity
     *
     * @throws \RuntimeException
     *
     * @return boolean
     */
    private function processFields($entity, $isEncryptOperation = true) {

        $encryptorMethod = $isEncryptOperation ? 'encrypt' : 'decrypt';


        $realClass = \Doctrine\Common\Util\ClassUtils::getClass($entity);

        $reflectionClass = new ReflectionClass($realClass);

        $properties = $reflectionClass->getProperties();

        $withAnnotation = false;

        foreach ($properties as $refProperty) {

            if($this->annReader->getPropertyAnnotation($refProperty, 'Doctrine\ORM\Mapping\ManyToOne')) {
                $getter = "get".ucfirst($refProperty->getName());
                $this->processFields($entity->$getter(), $isEncryptOperation);
            }

            if ($this->annReader->getPropertyAnnotation($refProperty, self::ENCRYPTED_ANN_NAME)) {

                $withAnnotation = true;

                // we have annotation and if it decrypt operation, we must avoid double decryption
                $propName = $refProperty->getName();

                if ($refProperty->isPublic()) {
                    $entity->$propName = $this->encryptor->$encryptorMethod($refProperty->getValue());

                } else {

                    $methodName = self::capitalize($propName);

                    if ($reflectionClass->hasMethod($getter = 'get' . $methodName) && $reflectionClass->hasMethod($setter = 'set' . $methodName)) {

                        $getInformation = $entity->$getter();
                        if($encryptorMethod == "decrypt") {
                            if(!is_null($getInformation) and !empty($getInformation)) {
                                if(substr($entity->$getter(), -5) == "<ENC>") {
                                    $currentPropValue = $this->encryptor->$encryptorMethod(substr($entity->$getter(), 0, -5));
                                    $entity->$setter($currentPropValue);
                                }
                            }
                        } else {
                            if(!is_null($getInformation) and !empty($getInformation)) {
                                if(substr($entity->$getter(), -5) != "<ENC>") {
                                    $currentPropValue = $this->encryptor->$encryptorMethod($entity->$getter()) . "<ENC>";
                                    $entity->$setter($currentPropValue);
                                }
                            }
                        }

                    } else {
                        throw new \RuntimeException(sprintf("Property %s isn't public and doesn't has getter/setter"));
                    }
                }
            }
        }


        return $withAnnotation;
    }

    /**
     * Encryptor factory. Checks and create needed encryptor
     * @param string $classFullName Encryptor namespace and name
     * @param string $secretKey Secret key for encryptor
     * @return EncryptorInterface
     * @throws \RuntimeException
     */
    private function encryptorFactory($classFullName, $secretKey) {
        $refClass = new \ReflectionClass($classFullName);
        if ($refClass->implementsInterface(self::ENCRYPTOR_INTERFACE_NS)) {
            return new $classFullName($secretKey);
        } else {
            throw new \RuntimeException('Encryptor must implements interface EncryptorInterface');
        }
    }
}
