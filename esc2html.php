<?php
/**
 * Utility to convert binary ESC/POS data to HTML
 */
require_once __DIR__ . '/vendor/autoload.php';

use ReceiptPrintHq\EscposTools\Parser\Context\Code2DStateStorage;
use ReceiptPrintHq\EscposTools\Parser\Parser;
use ReceiptPrintHq\EscposTools\Parser\Context\InlineFormatting;

$debugMode = false;
$targetFilename = "";

//error_log("esc2html starting", 0);
// Usage
if ($argc < 2) {
    print("Usage: php " . $argv[0] . " [--debug] filename \n"."zéro args");
    exit(1);
}
elseif(!isset($_POST["esc"])){
    if ($argv[1]=='--debug'){
        $debugMode = true;
        if (!isset($argv[2])) {
            print("Usage: php " . $argv[0] . " [--debug] filename ". $argc-1 . " arguments received\n");
            exit(1);
        }
        else $targetFilename = $argv[2];
        error_log("\nDebug mode enabled\n", 0);
    }
    else {  //First argument is not '--debug'
        if(isset($argv[2])) { // But there is at least 2 args
            print("Usage: php " . $argv[0] . " [--debug] filename \n". $argc-1 . " arguments received\n");
            exit(1);
        }
        else $targetFilename = $argv[1]; //The only argument is the filename.
    }
}
if($debugMode) error_log("Target filename: " . $targetFilename . "", 0);

if(!$debugMode) {
    error_reporting(E_ERROR | E_PARSE);  //Deprecation warnings are unwanted except for debugging
}

$parser = new Parser();
if (isset($_POST["esc"])) {
    // Load from string
    $parser -> addRaw($argv[2]);
} else {
    // Load in a file
    $fp = fopen($targetFilename, 'rb');
    if ( !$fp ) {
        if($debugMode) error_log("File ". $targetFilename . "not found.");
        exit(1);
    }

    $parser -> addFile($fp);
}

// Extract text
$commands = $parser -> getCommands();
$formatting = InlineFormatting::getDefault();
$outp = array();
$lineHtml = "";
$return = 0;
$bufferedImg = null;
$imgNo = 0;
$skipLineBreak = false;
$code2dStorage = new Code2DStateStorage();
$barcodeHeight = null;
$barcodeWidth = null;
$barcodeHri = null;

foreach ($commands as $i => $cmd) {
    if ($debugMode) error_log("". get_class($cmd) ."", 0); //Output the command class in the debug console

    if ($cmd -> isAvailableAs('InitializeCmd')) {
        $formatting = InlineFormatting::getDefault();
    }
    if ($cmd -> isAvailableAs('InlineFormattingCmd')) {
        $cmd -> applyToInlineFormatting($formatting);
    }
    if($cmd -> isAvailableAs('SelectCharCodeCmd')){
        //Let's set the character code table
        $formatting -> setCharCodeTable($cmd->getCodePage());
    }
    if ($cmd -> isAvailableAs('TextContainer')) {
        // Add text to line
        if ($debugMode) error_log("Text or unidentified command: '". $cmd->getText() ."' ", 0);
        $spanContentText = $cmd -> getText($formatting);
        $lineHtml .= span($formatting, $spanContentText);
    }
    if ($cmd -> isAvailableAs('HorizontalTabCmd')) {
        $lineHtml .= str_repeat('&nbsp;', 8);
        continue;
    }
    if ($cmd -> isAvailableAs('CarriageReturnCmd') || $cmd -> isAvailableAs('PrintAndReverseFeedLinesCmd')) {
        // Write fresh block element out to HTML
        if ($lineHtml === "") {
            $lineHtml = span($formatting);
        }

        $classes = getBlockClasses($formatting);
        $classesStr = implode(" ", $classes);
        $outp[] = wrapInline("<div class=\"$classesStr\">", "</div>", $lineHtml);
        $lineHtml = "";
        $return = $return + ($cmd -> isAvailableAs('CarriageReturnCmd') ? 1 : ($cmd -> getArg() ?? 1));
        continue;
    }
    if ($cmd -> isAvailableAs('LineBreak') && $skipLineBreak) {
        $skipLineBreak = false;
    } else if ($cmd -> isAvailableAs('LineBreak')) {
        // Write fresh block element out to HTML
        if ($lineHtml === "") {
            $lineHtml = span($formatting);
        }
        // Block-level formatting such as text justification
        $classes = getBlockClasses($formatting);
        $classesStr = implode(" ", $classes);
        $outp[] = wrapInline("<div class=\"$classesStr\"".($return?' style="margin-top:'.($return*-15).'px"':'').">", "</div>", $lineHtml);
        $return = 0;

        $count = $cmd -> isAvailableAs('PrintAndFeedCmd') || $cmd -> isAvailableAs('PrintAndFeedLinesCmd') ?
            ($cmd -> getArg() ?? 1) : 1;
        if(!is_numeric($count) || $count<=0) $count = 1;
        $count--;

        if($cmd -> isAvailableAs('PrintAndFeedCmd') || $cmd -> isAvailableAs('PrintAndFeedLinesCmd')){
            $count = $cmd -> isAvailableAs('PrintAndFeedCmd') || $cmd -> isAvailableAs('PrintAndFeedLinesCmd') ?
                ($cmd -> getArg() ?? 1) : 1;
            if(!is_numeric($count) || $count<=0) $count = 1;
            $count--;
            $lineHtml = "&nbsp;";
            for ($i = 0; $i < $count; $i++){
                $outp[] = wrapInline("<div class=\"$classesStr\">", "</div>", $lineHtml);
            }
        }
        $lineHtml = "";
    }
    if ($cmd -> isAvailableAs('GraphicsDataCmd') || $cmd -> isAvailableAs('GraphicsLargeDataCmd')) {
        $sub = $cmd -> subCommand();
        if ($sub -> isAvailableAs('StoreRasterFmtDataToPrintBufferGraphicsSubCmd')) {
            $bufferedImg = $sub;
        } else if ($sub -> isAvailableAs('PrintBufferredDataGraphicsSubCmd') && $bufferedImg !== null) {
            // Append and flush buffer
            $classes = getBlockClasses($formatting);
            $classesStr = implode(" ", $classes);
            $outp[] = wrapInline("<div class=\"$classesStr\">", "</div>", imgAsDataUrl($bufferedImg));
            $lineHtml = "";
        }
    } else if ($cmd -> isAvailableAs('ImageContainer')) {
        // Append and flush buffer
        if ($lineHtml && !(($commands[$i - 1] ?? null) ?-> isAvailableAs('ImageContainer') || ($commands[$i - 2] ?? null) ?-> isAvailableAs('ImageContainer'))) {
            $classes = getBlockClasses($formatting);
            $classesStr = implode(" ", $classes);
            $outp[] = wrapInline("<div class=\"$classesStr\">", "</div>", $lineHtml);
            $lineHtml = "";
        }
        $lineHtml .= imgAsDataUrl($cmd);
        // Should load into print buffer and print next line break, but we print immediately, so need to skip the next line break.
        if (($commands[$i + 1] ?? null) ?-> isAvailableAs('ImageContainer') || ($commands[$i + 2] ?? null) ?-> isAvailableAs('ImageContainer')) {
            $skipLineBreak = true;
        }
    }
    if ($cmd -> isAvailableAs('PulseCmd') || $cmd -> isAvailableAs('PulseOtherCmd')) {
        $outp[] = wrapInline("<div class=\"esc-line-command\">", "</div>", "<span class=\"command\">CASH REGISTER PULSE</span>");
    }
    else if ($cmd -> isAvailableAs('PowerOffCmd')) {
        $outp[] = wrapInline("<div class=\"esc-line-command\">", "</div>", "<span class=\"command\">POWER OFF PRINTER</span>");
    }
    else if ($cmd -> isAvailableAs('BuzzerCmd')) {
        $outp[] = wrapInline("<div class=\"esc-line-command\">", "</div>", "<span class=\"command\">BUZZER</span>");
    }
    else if ($cmd -> isAvailableAs('FeedAndCutCmd') || $cmd -> isAvailableAs('FeedAndCutOldCmd')) {
        $lines = $cmd -> getArg();
        if(!is_numeric($lines) || $lines<=0) $lines = 1;

        if ($lines == 27)
            $lines = $lines ? " WITH PARTIAL CUT " /*.($cmd -> isAvailableAs('FeedAndCutOldCmd')?'O':'N')*/ : '';
        else
            $lines = $lines ? " WITH $lines LINE(S) FEED " /*.($cmd -> isAvailableAs('FeedAndCutOldCmd')?'O':'N')*/ : '';

        $outp[] = wrapInline("<div class=\"esc-line-command\">", "</div>", "<span class=\"command\">PAPER CUT $lines</span>");
    }
    if ($cmd -> isAvailableAs('Code2DDataCmd')){
        $sub = $cmd -> subCommand();
        if ($debugMode)  {
            error_log("Subcommand ". get_class($sub) ."", 0); //Output the subcommand class in the debug console
            error_log("Function " . $sub->get_fn() ."",0);
            error_log("Data size:". $sub->getDataSize() ."",0);
            error_log("Data: " . $sub->get_data() ."",0);
        }
        if($sub->isAvailableAs('QRCodeSubCommand')){
            switch ($sub->get_fn()) {
                case 65:  //set model
                    $code2dStorage->setQRModel($sub->get_data());
                    break;
                case 67: //set module size
                    $code2dStorage->setModuleSize($sub->get_data());
                    break;
                case 69: //select error correction level
                    $code2dStorage->setErrorCorrectLevel($sub->get_data());
                    break;
                case 80:  //Store QR data
                    $code2dStorage->fillSymbolStorage($sub->get_data());
                    break;
                case 81:  //Print the QR code
                    $qrcodeURI = $code2dStorage->getQRCodeBase64URI();

                    if ($qrcodeURI == Code2DStatestorage::NO_DATA_ERROR){
                        if($debugMode) error_log("Warning:  QR code print ordered before contents stored.",0);
                        $imagefile = file_get_contents(__DIR__.'/NoQR.JPG');
                        if ($imagefile === false) {
                            #To make the netprinter work, provide a full path to the image file
                            if($debugMode) error_log("ERROR:  NoQR.JPG image not found in ".__DIR__, 0);
                            $imageData = '';
                            $imgSrc = '';
                        }
                        else {
                            $imageData = base64_encode($imagefile);
                            $imgSrc = 'data:image/jpeg;base64,' . $imageData;
                        }
                        $qrcodeData = Code2dStatestorage::NO_DATA_ERROR;
                        $outp[] = "<div class=\"esc-line esc-justify-center\"><img class=\"esc-bitimage\" src=\"$imgSrc\" alt=\"$qrcodeData\" /></div>";
                    }
                    else {
                        $qrcodeData = $code2dStorage->getQRCodeData();
                        $outp[] = "<div class=\"esc-line esc-justify-center\"><img class=\"esc-bitimage\" src=\"$qrcodeURI\" alt=\"$qrcodeData\" /></div>";
                    }
                    break;
                case 82:  //Transmit size information of symbol storage data.
                    # TODO: maybe implement by printing the info?
                    break;
            }
        }
    }
    if ($cmd -> isAvailableAs('SetBarcodeHeightCmd')) {
        $barcodeHeight = $cmd -> getArg();
    } else if ($cmd -> isAvailableAs('SetBarcodeWidthCmd')) {
        $barcodeWidth = $cmd -> getArg();
    } else if ($cmd -> isAvailableAs('SelectHriPrintPosCmd')) {
        $barcodeHri = $cmd -> getArg();
    } else if ($cmd -> isAvailableAs('PrintBarcodeCmd')) {
        $types = [
            0  => 'TypeUpcA',
            65 => 'TypeUpcA',
            1  => 'TypeUpcE',
            66 => 'TypeUpcE',
            2  => 'TypeEan13',
            67 => 'TypeEan13',
            3  => 'TypeEan8',
            68 => 'TypeEan8',
            4  => 'TypeCode39',
            69 => 'TypeCode39',
            6  => 'TypeCodabar',
            71 => 'TypeCodabar',
            72 => 'TypeCode93',
            73 => 'TypeCode128',
        ];
        $type = $types[$cmd->getType()] ?? null;
        $data = $cmd -> subCommand()->getData();
        $classes = getBlockClasses($formatting);
        $classesStr = implode(" ", $classes);
        if ($type){
            $renderer = new \Picqer\Barcode\Renderers\PngRenderer();
            $renderer->setBackgroundColor([255, 255, 255]);
            if (!class_exists(\Imagick::class)) {
                $renderer->useGd();
            } else {
                $renderer->useImagick();
            }
            $type = '\\Picqer\\Barcode\\Types\\' . $type;
            $barcode = (new $type)->getBarcode($data);
            $imgSrc = base64_encode($renderer->render($barcode, $barcodeWidth ?? $barcode->getWidth(), $barcodeHeight ?? 40));
            $lineHtml = "<img class=\"esc-bitimage\" src=\"data:image/jpeg;base64,{$imgSrc}\" alt=\"{$data}\" />";
            
        } else {
            $classesStr .= ' esc-line-command';
            $lineHtml = "<span class=\"command\">BARCODE {$cmd->getType()} (NO PREVIEW)" . (in_array($barcodeHri, [1, 2, 3]) ? '' : " [$data]") . "</span>";
        }
        if (in_array($barcodeHri, [1, 3])) {
            $lineHtml = "<div>$data</div>" . $lineHtml;
        }
        if (in_array($barcodeHri, [2, 3])) {
            $lineHtml = $lineHtml . "<div>$data</div>";
        }
        $outp[] = wrapInline("<div class=\"$classesStr\">", "</div>", wrapInline("<span class=\"esc-justify-center\">", "</span>", $lineHtml));
        $lineHtml = ""; // flush buffer
        $barcodeWidth = $barcodeHeight = $barcodeHri = null;
    }
}

// Stuff we need in the HTML header
const CSS_FILE = __DIR__ . "/src/resources/esc2html.css";
$width = $width ?? '80mm';
$metaInfo = array_merge(
    array(
        "<meta charset=\"UTF-8\">",
        "<style>"
    ),
    [
        str_replace([': ',' {','  ',chr(13),chr(10)],[':','{','','',''],trim(file_get_contents(CSS_FILE))),
        ".esc-receipt{width:{$width};min-width:{$width};overflow:hidden;}"
    ],
    array(
        "</style>"
    )
);

// Final document assembly
$receipt = wrapBlock("<div class=\"esc-receipt\">", "</div>", $outp);
$head = wrapBlock("<head>", "</head>", $metaInfo);
$body = wrapBlock("<body>", "</body>", $receipt);
$html = wrapBlock("<html>", "</html>", array_merge($head, $body), false);
echo "<!DOCTYPE html>\n" . implode("\n", $html) . "\n";
if($debugMode) error_log("'". $targetFilename . "' converted to HTML",0);


function imgAsDataUrl($bufferedImg)
{
    $imgAlt = "Image " . $bufferedImg -> getWidth() . 'x' . $bufferedImg -> getHeight();
    $imgSrc = "data:image/png;base64," . base64_encode($bufferedImg -> asPng());
    $imgWidth = $bufferedImg -> getWidth() / 2; // scaling, images are quite high res and dwarf the text
    $bufferedImg = null;
    return "<img class=\"esc-bitimage\" src=\"$imgSrc\" alt=\"$imgAlt\" width=\"{$imgWidth}px\" />";
}

function wrapInline($tag, $closeTag, $content)
{
    return $tag . $content . $closeTag;
}

function wrapBlock($tag, $closeTag, array $content, $indent = true)
{
    $ret = array();
    $ret[] = $tag;
    foreach ($content as $line) {
        $ret[] = ($indent ? '  ' : '') . $line;
    }
    $ret[] = $closeTag;
    return $ret;
}

function span(InlineFormatting $formatting, $spanContentText = false)
{
    // Gut some features-
    if ($formatting -> widthMultiple > 8) {
        // Widths > 2 are not implemented. Cap the width at 2 to avoid formatting issues.
        $formatting -> widthMultiple = 8;
    }
    if ($formatting -> heightMultiple > 8) {
        // Widths > 8 are not implemented either
        $formatting -> heightMultiple = 8;
    }

    // Determine formatting classes to use
    $classes = array();

    if ($formatting -> bold) {
        $classes[] = "esc-emphasis";
    }
    if ($formatting -> underline > 0) {
        $classes[] = $formatting -> underline > 1 ? "esc-underline-double" : "esc-underline";
    }
    if ($formatting -> invert) {
        $classes[] = "esc-invert";
    }
    if ($formatting -> upsideDown) {
        $classes[] = "esc-upside-down";
    }
    if ($formatting -> font == 1) {
        $classes[] = "esc-font-b";
    }
    if ($formatting -> widthMultiple > 1 || $formatting -> heightMultiple > 1) {
        $classes[] = "esc-text-scaled";
        // Add a single class representing height and width scaling
        $widthClass = $formatting -> widthMultiple > 1 ? "-width-" . $formatting -> widthMultiple : "";
        $heightClass = $formatting -> heightMultiple > 1 ? "-height-" . $formatting -> heightMultiple : "";
        $classes[] = "esc" . $widthClass . $heightClass;
    }

    // Provide span content as HTML
    if ($spanContentText === false) {
        $spanContentHtml = "&nbsp;";
    } else {
        $spanContentHtml = htmlentities($spanContentText);
    }

    // Output span with any non-default classes
    if (count($classes) == 0) {
        return $spanContentHtml;
    }
    return "<span class=\"". implode(" ", $classes) . "\">" . $spanContentHtml . "</span>";
}

function getBlockClasses($formatting)
{
    $classes = ["esc-line"];
    if ($formatting -> justification === InlineFormatting::JUSTIFY_CENTER) {
        $classes[] = "esc-justify-center";
    } else if ($formatting -> justification === InlineFormatting::JUSTIFY_RIGHT) {
        $classes[] = "esc-justify-right";
    }
    return $classes;
}
