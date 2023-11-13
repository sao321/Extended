<?php

declare(strict_types=1);

namespace JeroenDesloovere\VCard\Parser;

use JeroenDesloovere\VCard\Exception\ParserException;
use JeroenDesloovere\VCard\Formatter\VcfFormatter;
use JeroenDesloovere\VCard\Parser\Property\NodeParserInterface;
use JeroenDesloovere\VCard\Property\NodeInterface;
use JeroenDesloovere\VCard\VCard;
use JeroenDesloovere\VCard\Property\Parameter\Version;
use JeroenDesloovere\VCard\Property\Parameter\Kind;

final class VcfParser implements ParserInterface
{
    /** @var NodeParserInterface[] - f.e. ['ADR' => JeroenDesloovere\VCard\Parser\Property\AddressParser] */
    private $parsers = [];

    public function __construct()
    {
        /**
         * We define all possible node parsers
         *
         * @var NodeInterface $node
         */
        foreach (VCard::POSSIBLE_VALUES as $node) {
            $this->parsers[$node::getNode()] = $node::getParser();
        }
    }

    /**
     * Returns all found vCard objects
     *
     * @param string $content
     * @return VCard[]
     * @throws ParserException
     */
    public function getVCards(string $content): array
    {
        return array_map(function ($vCardContent) {
            return $this->parseVCard($vCardContent);
        }, $this->splitIntoVCards($content));
    }

    private function parseParameters(?string $parameters): array
    {
        if ($parameters === null) {
            return [];
        }

        /** @var string[] $parametersArray */
        $parametersArray = explode(';', $parameters);
        $parsedParameters = [];
        foreach ($parametersArray as $parameter) {
            /**
             * @var string $node
             * @var string $value
             */
            @list($node, $value) = explode('=', $parameter, 2);

            if (array_key_exists($node, $this->parsers)) {
                $parsedParameters[$node] = $this->parsers[$node]->parseVcfString($value);
            }
        }

        return $parsedParameters;
    }

    private function parseVCard(string $content): VCard
    {
        $vCard = $this->createVcardObjectWithProperties($content);

        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $this->parseVCardContentLine($line, $vCard);
        }

        return $vCard;
    }

    private function createVcardObjectWithProperties(string $content): VCard
    {
        $vcardProperties = array(
          Kind::getNode() => null,
          Version::getNode() => null);

        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            /**
             * @var string $node
             * @var string $value
             */
            @list($node, $value) = explode(':', $line, 2);
            if (array_key_exists($node, $this->parsers)) {
                // Only check on either Kind or Version node
                if ($node == Kind::getNode() || $node == Version::getNode()) {
                    $vcardProperties[$node] = $this->parsers[$node]->parseVcfString($value);
                }
            }
        }

        return new VCard($vcardProperties[Kind::getNode()], $vcardProperties[Version::getNode()]);
    }

    private function parseVCardContentLine(string $line, VCard &$vCard): void
    {
        // Strip grouping information. We don't use the group names. We
        // simply use a list for entries that have multiple values.
        // As per RFC, group names are alphanumerical, and end with a
        // period (.).
        $line = preg_replace('/^\w+\./', '', trim($line));

        /**
         * @var string $node
         * @var string $value
         */
        @list($node, $value) = explode(':', $line, 2);

        /**
         * @var string $node
         * @var string|null $parameterContent
         */
        @list($node, $parameterContent) = explode(';', $node, 2);

        // Skip parameters that we can not parse yet, because the property/parser does not exist yet.
        // Feel free to create a PR to add a new Property Parser
        if (!array_key_exists($node, $this->parsers)) {
            return;
        }

        try {
            $vCard->add($this->parsers[$node]->parseVcfString($value, $this->parseParameters($parameterContent)));
        } catch (\Exception $e) {
            // Ignoring properties that throw error. F.e. if they are allowed only once.
        }
    }

    /**
     * Split string into array, each array item contains vCard content.
     *
     * @param string $content - The full content from the .vcf file.
     * @return array - Is an array with the content for all possible vCards.
     * @throws ParserException
     */
    private function splitIntoVCards(string $content): array
    {
        // Normalize new lines.
        $content = trim(str_replace(["\r\n", "\r"], "\n", $content));

        if (!preg_match('/^BEGIN:VCARD[\s\S]+END:VCARD$/', $content)) {
            throw ParserException::forUnreadableVCard($content);
        }

        // Remove first BEGIN:VCARD and last END:VCARD
        $content = substr($content, 12, -10);

        // RFC2425 5.8.1. Line delimiting and folding
        // Unfolding is accomplished by regarding CRLF immediately followed by
        // a white space character (namely HTAB ASCII decimal 9 or. SPACE ASCII
        // decimal 32) as equivalent to no characters at all (i.e., the CRLF
        // and single white space character are removed).
        $content = preg_replace("/\n(?:[ \t])/", '', $content);

        // If multiple vcards split per vcard
        $contentPerVCard = preg_split(
            '/\n' . VcfFormatter::VCARD_END . '\s+' . VcfFormatter::VCARD_BEGIN . '\n/',
            $content
        );

        return is_array($contentPerVCard) ? $contentPerVCard : [];
    }
}
