<?php
namespace ReceiptPrintHq\EscposTools\Parser\Command;

use ReceiptPrintHq\EscposTools\Parser\Command\EscposCommand;

class CommandFiveArgs extends EscposCommand
{
    private $arg1 = null;
    private $arg2 = null;
    private $arg3 = null;
    private $arg4 = null;
    private $arg5 = null;

    public function addChar($char)
    {
        if ($this -> arg1 === null) {
            $this -> arg1 = ord($char);
            return true;
        } elseif ($this -> arg2 === null) {
            $this -> arg2 = ord($char);
            return true;
        } elseif ($this -> arg3 === null) {
            $this -> arg3 = ord($char);
            return true;
        } elseif ($this -> arg4 === null) {
            $this -> arg4 = ord($char);
            return true;
        } elseif ($this -> arg5 === null) {
            $this -> arg5 = ord($char);
            return true;
        }
        return false;
    }

    public function getArg1()
    {
        return $this -> arg1;
    }

    public function getArg2()
    {
        return $this -> arg2;
    }

    public function getArg3()
    {
        return $this -> arg3;
    }

    public function getArg4()
    {
        return $this -> arg4;
    }

    public function getArg5()
    {
        return $this -> arg5;
    }
}
