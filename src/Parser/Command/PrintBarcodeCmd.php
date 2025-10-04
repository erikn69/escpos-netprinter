<?php
namespace ReceiptPrintHq\EscposTools\Parser\Command;

use ReceiptPrintHq\EscposTools\Parser\Command\EscposCommand;

class PrintBarcodeCmd extends EscposCommand
{
    private $m = null;
    private $subCommand = null;

    public function addChar($char)
    {
        if ($this -> m === null) {
            $this -> m = ord($char);
            if ((0 <= $this -> m) && ($this -> m <= 6)) {
                $this -> subCommand = new BarcodeAData($this -> context);
            } elseif ((65 <= $this -> m) && ($this -> m <= 78)) {
                $this -> subCommand = new BarcodeBData($this -> context);
            }
            return true;
        }
        if ($this -> subCommand === null) {
            return false;
        }
        return $this -> subCommand -> addChar($char);
    }

    public function subCommand()
    {
        // TODO rename and take getSubCommand() name.
        return $this -> subCommand;
    }

    public function getType() {
        return $this -> m;
    }
}
