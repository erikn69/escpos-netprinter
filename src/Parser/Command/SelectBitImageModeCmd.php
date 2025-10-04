<?php
namespace ReceiptPrintHq\EscposTools\Parser\Command;

use ReceiptPrintHq\EscposTools\Parser\Command\DataCmd;
use Imagick;

class SelectBitImageModeCmd extends EscposCommand implements ImageContainer
{

    private $m = null;

    private $p1 = null;

    private $p2 = null;

    private $height, $width;

    private $data = "";

    private $dataSize = null;

    public function addChar($char)
    {
        if ($this->m === null) {
            $this->m = ord($char);
            return true;
        } else if ($this->p1 === null) {
            $this->p1 = ord($char);
            return true;
        } elseif ($this->p2 === null) {
            $this->p2 = ord($char);
            $this->width = $this->p1 + $this->p2 * 256;
            if ($this->m == 32 || $this->m == 33) {
                $this->dataSize = $this->width * 3;
                $this->height = 24;
            } else {
                $this->dataSize = $this->width;
                $this->height = 8;
            }
            return true;
        } else if (strlen($this->data) < $this->dataSize) {
            $this->data .= $char;
            return true;
        }
        return false;
    }

    public function getHeight()
    {
        return $this -> height;
    }

    public function getWidth()
    {
        return $this -> width;
    }
    
    protected function asReflectedPbm()
    {
        // Gemerate a PBM image from the source data. If we add a PBM header to the column
        // format ESC/POS data with the width and height swapped, then we get a valid PBM, with
        // the image reflected diagonally compared with the original.
        return "P4\n" . $this -> getHeight() . " " . $this -> getWidth() . "\n" . $this -> data;
    }
    
    public function asPbm()
    {
        // Reflect image diagonally from internally generated PBM
        $pbmBlob = $this -> asReflectedPbm();
        if (!class_exists(Imagick::class)) {
            $img = $this->pbmP4ToImage($pbmBlob);
            $white = imagecolorallocate($img, 255, 255, 255);
            $rotated = imagerotate($img, 90, $white);
            imagedestroy($img);
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
            ob_start();
            imagepng($rotated);
            imagedestroy($rotated);
            return ob_get_clean();
        }
        $im = new Imagick();
        $im -> readImageBlob($pbmBlob, 'pbm');
        $im -> rotateImage('#fff', 90.0);
        $im -> flopImage();
        return $im -> getImageBlob();
    }
    
    public function asPng()
    {
        // Just a format conversion PBM -> PNG
        if (!class_exists(Imagick::class)) {
            $pbmBlob = $this->asReflectedPbm();
            $img = $this->pbmP4ToImage($pbmBlob);

            ob_start();
            imagepng($img);
            imagedestroy($img);
            return ob_get_clean();
        }
        $pbmBlob = $this -> asPbm();
        $im = new Imagick();

        $im -> readImageBlob($pbmBlob, 'pbm');
        $im->setResourceLimit(6, 1); // Prevent libgomp1 segfaults, grumble grumble.
        $im -> setFormat('png');
        return $im -> getImageBlob();
    }

    private function pbmP4ToImage(string $pbmBlob) {
        $fp = fopen("php://memory", "r+");
        fwrite($fp, $pbmBlob);
        rewind($fp);

        $header = trim(fgets($fp));
        do {
            $pos = ftell($fp);
            $line = trim(fgets($fp));
        } while ($line !== false && str_starts_with($line, "#"));
        fseek($fp, $pos);

        [$width, $height] = array_map("intval", preg_split('/\s+/', trim(fgets($fp))));

        $img = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $width, $height, $white);

        $rowBytes = (int)ceil($width / 8);
        for ($y = 0; $y < $height; $y++) {
            $row = fread($fp, $rowBytes);
            $bits = unpack("C*", $row);
            $x = 0;
            foreach ($bits as $byte) {
                for ($bit = 7; $bit >= 0; $bit--) {
                    if ($x >= $width) break;
                    $val = ($byte >> $bit) & 1;
                    if ($val === 1) {
                        imagesetpixel($img, $x, $y, $black);
                    }
                    $x++;
                }
            }
        }

        fclose($fp);
        return $img; // recurso GD listo
    }

    private function asPngUsingGD($pbmBlob) {
        $fp = fopen("php://memory", "r+");
        fwrite($fp, $pbmBlob);
        rewind($fp);

        $header = trim(fgets($fp));
        do {
            $pos = ftell($fp);
            $line = trim(fgets($fp));
        } while ($line !== false && str_starts_with($line, "#"));
        fseek($fp, $pos);

        [$width, $height] = array_map("intval", preg_split('/\s+/', trim(fgets($fp))));
        $img = imagecreatetruecolor($width, $height);
        imagefilledrectangle($img, 0, 0, $width, $height, imagecolorallocate($img, 255, 255, 255));

        $black = imagecolorallocate($img, 0, 0, 0);
        $rowBytes = (int)ceil($width / 8);
        for ($y = 0; $y < $height; $y++) {
            $row = fread($fp, $rowBytes);
            $bits = unpack("C*", $row);
            $x = 0;
            foreach ($bits as $byte) {
                for ($bit = 7; $bit >= 0; $bit--) {
                    if ($x >= $width) break;
                    $val = ($byte >> $bit) & 1;
                    if ($val === 1) {
                        imagesetpixel($img, $x, $y, $black);
                    }
                    $x++;
                }
            }
        }

        fclose($fp);
        ob_start();
        imagepng($img);
        imagedestroy($img);
        return ob_get_clean();
    }
}
