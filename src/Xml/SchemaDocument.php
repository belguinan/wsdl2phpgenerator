<?php

/*
 * This file is part of the WSDL2PHPGenerator package.
 * (c) WSDL2PHPGenerator.
 */

namespace Wsdl2PhpGenerator\Xml;

use DOMDocument;
use DOMElement;
use Exception;
use Wsdl2PhpGenerator\ConfigInterface;
use Wsdl2PhpGenerator\StreamContextFactory;

/**
 * A SchemaDocument represents an XML element which contains type elements.
 *
 * The element may reference other schemas to generate a tree structure.
 */
class SchemaDocument extends XmlNode
{
    /**
     * The url representing the location of the schema.
     *
     * @var string
     */
    protected $url;

    /**
     * The schemas which are referenced by the current schema.
     *
     * @var SchemaDocument[]
     */
    protected $referereces;

    /**
     * The urls of schemas which have already been loaded.
     *
     * We keep a record of these to avoid cyclic imports.
     *
     * @var string[]
     */
    protected static $loadedUrls;

    public function __construct(ConfigInterface $config, $xsdUrl)
    {
        $this->url = $xsdUrl;

        // Generate a stream context used by libxml to access external resources.
        // This will allow DOMDocument to load XSDs through a proxy.
        $streamContextFactory = new StreamContextFactory();
        libxml_set_streams_context($streamContextFactory->create($config));

        $document = new DOMDocument();
        $loaded   = $document->load($xsdUrl);
        if (!$loaded) {
            throw new Exception('Unable to load XML from '.$xsdUrl);
        }

        parent::__construct($document, $document->documentElement);
        // Register the schema to avoid cyclic imports.
        self::$loadedUrls[] = $xsdUrl;

        // Locate and instantiate schemas which are referenced by the current schema.
        // A reference in this context can either be
        // - an import from another namespace: http://www.w3.org/TR/xmlschema-1/#composition-schemaImport
        // - an include within the same namespace: http://www.w3.org/TR/xmlschema-1/#compound-schema
        $this->referereces = [];
        foreach ($this->xpath('//wsdl:import/@location|'.
                                '//s:import/@schemaLocation|'.
                                '//s:include/@schemaLocation') as $reference) {
            $referenceUrl = $reference->value;
            if (strpos($referenceUrl, '//') === false) {
                $referenceUrl = dirname($xsdUrl).'/'.$referenceUrl;
            }
            
            // remove ../ from urls
            if (strpos($referenceUrl, '../') !== false) {

                $baseUrlArray = explode('/', str_replace(['http://', 'https://', '../'], '', dirname($xsdUrl)));

                $baseUrlArray = array_slice($baseUrlArray, 0, -1 * substr_count($referenceUrl, '../'));

                $cleanUrl = parse_url($referenceUrl)['scheme'] . '://' . str_replace('../', '', implode('/', $baseUrlArray) . '/' . $reference->value);

                $referenceUrl = $cleanUrl;
            }

            if (!in_array($referenceUrl, self::$loadedUrls)) {
                $this->referereces[] = new SchemaDocument($config, $referenceUrl);
            }
        }
    }

    /**
     * Parses the schema for a type with a specific name.
     *
     * @param string $name The name of the type
     *
     * @return DOMElement|null Returns the type node with the provided if it is found. Null otherwise.
     */
    public function findTypeElement($name)
    {
        $type = null;

        $elements = $this->xpath('//s:simpleType[@name=%s]|//s:complexType[@name=%s]', $name, $name);
        if ($elements->length > 0) {
            $type = $elements->item(0);
        }

        if (empty($type)) {
            foreach ($this->referereces as $import) {
                $type = $import->findTypeElement($name);
                if (!empty($type)) {
                    break;
                }
            }
        }

        return $type;
    }
}
