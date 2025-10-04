<?php
namespace ReceiptPrintHq\EscposTools\Parser\Command;

use ReceiptPrintHq\EscposTools\Parser\Command\Command;

class FeedAndCutOldCmd extends Command implements LineBreak
{
    private $arg1 = null;
    private $arg2 = null;

    public function addChar($char)
    {
        return false;
    }

    public function getArg(){
        return 1;
    }
}
