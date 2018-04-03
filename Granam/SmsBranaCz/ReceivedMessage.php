<?php
namespace Granam\SmsBranaCz;

use Granam\Strict\Object\StrictObject;

class ReceivedMessage extends StrictObject
{
    /** @var string */
    private $number;
    /** @var string */
    private $text;
    /** @var \DateTime */
    private $at;

    public function __construct(string $text, string $number, \DateTime $at)
    {
        $this->text = $text;
        $this->number = $number;
        $this->at = $at;
    }

    /**
     * @return string
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * @return string
     */
    public function getNumber(): string
    {
        return $this->number;
    }

    /**
     * @return \DateTime
     */
    public function getAt(): \DateTime
    {
        return $this->at;
    }
}