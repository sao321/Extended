<?php

declare(strict_types=1);

namespace JeroenDesloovere\VCard\Parser;

use JeroenDesloovere\VCard\VCard;

interface ParserInterface
{
    /**
     * @param string $content
     * @return VCard[]
     */
    public function getVCards(string $content): array;
}
