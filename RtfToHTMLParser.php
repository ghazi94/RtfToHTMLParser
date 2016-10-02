<?php

/**
 * Declare appropriate namespace here if part of a project
 */

/**
 * Configurable RTF to HTML Format Converter
 * !!! Only parses textual content - Ignores stylesheets, picture, etc.
 * --------------------------------------------------------------------
 * Support for :-
 * Numeric and bulleted lists (Upto 1 indentation level, Also list item should be a
 * continous block, cannot contain line breaks)
 * Bold, Underline, Italic Text
 * UTF-8 Characters
 * Color Texts
 * Tab and Spaces Indentation
 *
 * No Support for :-
 * Multi sized fonts
 * Multi font family
 * Multi level indented texts
 * Non textual content - pictures, stylesheets, etc.

 */
class RtfToHtmlParser
{

    private $filePath = null;
    private $resultStack = array();

    /**
     * Use when the RTF file is divided into chunks (which will be individually
     * stored in a database) by a separator.
     * Make sure the separator is unique compared to the contents of the file
     */
    private $sectionSeparation = array(
            "exist" => false,
            "separator" => "----"
        );

    /**
     * File Chunks (according to specified separator) for serialized reading
     * Will contain the whole file contents as string if sectionSeperation is true
     */
    private $chunks = array();

    /**
     * @const HTML_ENCODE_TYPE value for htmlentities
     */
    const HTML_ENCODE_TYPE = ENT_QUOTES;

    /**
     * @const PARSE_CONFIG_BEGIN_KEY value for parseConfig array
     */
    const PARSE_CONFIG_BEGIN_KEY = "content_begin_";

    /**
     * @const PARSE_CONFIG_BEGIN_KEY value for parseConfig array
     */
    const PARSE_CONFIG_CHECK_FORMATTING_KEY = "check_style_";

    /**
     * @const PARSE_CONFIG_CHECK_LIST value for parseConfig array
     */
    const PARSE_CONFIG_CHECK_LIST = "check_list";

    /**
     * Contains configuration for parser
     */
    private $parseConfig = array(
        // Check whenever a textual content is present
        // More specific ones take more precedence
        "rtf_symbol_control" => array(
            "value" => "\\par \\pard\\plain",
            "extra" => null
        ),
        "content_end" => array(
            "value" => "}",
            "extra" => null
        ),
        "line_separators" => array(
            "open_tag" => "<div>",
            "close_tag" => "</div>"
        )
    );

    /**
     * Constructor function
     *
     * @param resource $filePath - Name of the file to be parsed
     * @param array    $config   - Configuration options like separator, etc.
     *
     * @return null
     */
    public function __construct($filePath, $config)
    {
        if ($filePath) {
            $this->filePath = $filePath;
        }

        if ($config) {
            if (isset($config["sectionSeparation"]) &&
                isset($config["sectionSeparation"]["separator"])) {
                $this->sectionSeparation["exist"] = true;
                $this->sectionSeparation["separator"] = $config["sectionSeparation"]["separator"];
            }
        }

        /* ------- Self Initialization Code ------- */
        // !!! parseConfig relies on the property of PHP arrays (associative or not) which preserves
        // the order in which the elements are inserted (similar to a ordered hash map indexed by
        // the creation time)

        // Content beginner pattern 1, denotes the start of textual content
        // Put LEAST SPECIFIC PATTERNS on the top
        $this->parseConfig[self::PARSE_CONFIG_BEGIN_KEY . "1"] = array(
            "value" => "\\rtlch \\ltrch",
            "extra" => null
        );
        $this->parseConfig[self::PARSE_CONFIG_BEGIN_KEY . "2"] = array(
            "value" => "\\rtlch \\ltrch\\loch",
            "extra" => null
        );

        // Pattern for detecting patterns/pattern combinations of bold, italic, underline
        // Put MOST SPECIFIC PATTERNS on top
        $this->parseConfig[self::PARSE_CONFIG_CHECK_FORMATTING_KEY . "bold_italic_underline"] = array(
            "value" => "\\i\\ul\\ulc0\\b\\ai\\ab\\rtlch",
            "extra" => array(
                "css_class" => "rtf-bold rtf-italic rtf-underline"
            )
        );
        $this->parseConfig[self::PARSE_CONFIG_CHECK_FORMATTING_KEY . "bold_italic"] = array(
            "value" => "\\i\\b\\ai\\ab\\rtlch",
            "extra" => array(
                "css_class" => "rtf-bold rtf-italic"
            )
        );
        $this->parseConfig[self::PARSE_CONFIG_CHECK_FORMATTING_KEY . "bold_underline"] = array(
            "value" => "\\ul\\ulc0\\b\\ab\\rtlch",
            "extra" => array(
                "css_class" => "rtf-bold rtf-underline"
            )
        );
        $this->parseConfig[self::PARSE_CONFIG_CHECK_FORMATTING_KEY . "italic_underline"] = array(
            "value" => "\\i\\ul\\ulc0\\ai\\rtlch",
            "extra" => array(
                "css_class" => "rtf-italic rtf-underline"
            )
        );
        $this->parseConfig[self::PARSE_CONFIG_CHECK_FORMATTING_KEY . "bold"] = array(
            "value" => "\\b\\ab\\rtlch",
            "extra" => array(
                "css_class" => "rtf-bold"
            )
        );
        $this->parseConfig[self::PARSE_CONFIG_CHECK_FORMATTING_KEY . "italic"] = array(
            "value" => "\\i\\ai\\rtlch",
            "extra" => array(
                "css_class" => "rtf-italic"
            )
        );
        $this->parseConfig[self::PARSE_CONFIG_CHECK_FORMATTING_KEY . "underline"] = array(
            "value" => "\\ul\\ulc0\\rtlch",
            "extra" => array(
                "css_class" => "rtf-underline"
            )
        );
        $this->parseConfig[self::PARSE_CONFIG_CHECK_LIST] = array(
            "value" => "\\listtext",
            "extra" => array(
                "level_0" => "\\ilvl0",
                "level_1" => "\\ilvl1",
                "numeric_start" => array(
                    "value" => "1.\\tab",
                    "wrapper" => "ol"
                ),
                "bullet_start" => array(
                    "value" => "'3f\\tab",
                    "wrapper" => "ul"
                )
            )
        );
    }

    /**
     * Getter for the main result stack (split by separator)
     *
     * @return resource $config - Configuration options like separator, etc.
     */
    public function getResultStack()
    {
        return $this->resultStack;
    }

    /**
     * Call this function to start RTF to HTML parsing
     *
     */
    public function parse()
    {
        // Build chunk according to separator enabled or disabled
        $this->chunkBuilder();

        // Now start RTF to HTML Conversion
        foreach ($this->chunks as $chunk) {
            $this->resultStack[] = $this->getHtmlCode($chunk);
        }
    }

    /**
     * Separates the RTF blob by separator
     *
     */
    public function chunkBuilder()
    {
        // Individual chunk string. Saves to $chunks array and
        // resets when opening and ending separator pair is found
        $rtfChunk = "";
        $filePath = $this->filePath;

        if (!$filePath) {
            throw new \Exception("Null/Empty file path passed!");
        }

        if ($this->sectionSeparation["exist"]) {
            // Read the file line by line and separate it into chunks
            $fileHandle = fopen($filePath, 'r') or die('Failed to open file for reading.');
            if ($fileHandle) {

                // Count of separator which triggers whenever a opening closing pair is found
                $separatorCount = 0;

                while (!feof($fileHandle)) {
                    // Iterate to another line
                    $line = fgets($fileHandle);
                    $separator = strpos($line, $this->sectionSeparation["separator"]);
                    if ($separator !== false) {
                        // A separator pair opens
                        $separatorCount = $separatorCount + 1;
                        if ($separatorCount < 2) {
                            // Skip to the next line
                        } else {
                            // A closing separator pair is found
                            // Push existing chunk to chunks
                            $this->chunks[] = $rtfChunk;
                            // Reset chunk
                            $rtfChunk = "";
                            // Continue building the new chunk until another separator is found
                            $separatorCount = 1;
                        }
                    } else {
                        if ($separatorCount == 1) {
                            $rtfChunk = $rtfChunk . $line;
                        }
                    }
                }
            }
            fclose($fileHandle);
        } else {
            // Read the whole file into a single string
            $rtfChunk = file_get_contents($filePath);
            $this->chunks[] = $rtfChunk;
        }
    }

    /**
     * RTF to HTML code converter
     *
     * @param string $chunk - RTF Chunk to be parsed
     *
     * @return string $result - HTML Parsed result
     */
    public function getHtmlCode($chunk)
    {
        $result = "";

        // Loop switch to stop parsing
        $loopSwitch = true;

        // Denotes which beginning pattern from parseConfig was used
        $contentBeginStyle = null;
        $contentStart = false;

        // Stores if a list type content match was detected in a previous iteration
        $listMatch = false;
        // List type, ordered or unordered
        $listType = "ul";
        // Stores the items of a list in the event of list detection
        $listArray = array();

        $futureLineBreak = false;
        $futureIndentation = "";
        $futureCssClasses = array();

        /*
         * Advanced Feature. Enable it in later versions
        $loopTimeline = $this->intializeLoopTimeline();
        */

        do {
            // Find content starter for a textual block
            list($contentBeginStyle, $contentStart) = $this->getContentBeginStyle($chunk);

            // Check whether a content started has been found or not
            if ($contentStart !== false) {

                // Move previous iterations current to previous
                /*
                 * Advanced Feature. Enable it in later versions
                if (isset($loopTimeline["current"]["contentStart"])) {
                    foreach ($loopTimeline["current"] as $key => $value) {
                        $loopTimeline["previous"][$key] = $value;
                    }
                }
                */

                /* ----------- Capture the content in between ------------ */
                $rtfSymbolsChunk = substr(
                    // Relative to chunk
                    $chunk,
                    strpos($chunk, $this->parseConfig["rtf_symbol_control"]["value"]),
                    $contentStart + strlen($this->parseConfig[self::PARSE_CONFIG_BEGIN_KEY . $contentBeginStyle]["value"])
                );

                // Trim down the string to start position
                $beginTrimmedChunk = substr(
                    // Relative to chunk
                    $chunk,
                    $contentStart + strlen($this->parseConfig[self::PARSE_CONFIG_BEGIN_KEY . $contentBeginStyle]["value"])
                );

                // Find the ending of this content
                $contentEnd = strpos(
                    // Relative to beginTrimmedChunk
                    $beginTrimmedChunk,
                    $this->parseConfig["content_end"]["value"]
                );

                // Find the closure of contend and capture the text in between
                $slicedText = substr(
                    // Relative to beginTrimmedChunk
                    $beginTrimmedChunk,
                    0,
                    $contentEnd
                );

                // Check the newline character position.
                //Taking offset as 1 since the first charachter is a newline
                $newLineBreak = strpos(
                    // Relative to beginTrimmedChunk
                    $beginTrimmedChunk,
                    PHP_EOL,
                    1
                );

                // Trim further till the end of slicedText above (Since the content in between is captured)
                $endTrimmedChunk = substr(
                    // Relative to beginTrimmedChunk
                    $beginTrimmedChunk,
                    strpos($beginTrimmedChunk, $this->parseConfig["content_end"]["value"]) + 1
                );
                /* -----------  ------------ */

                // Store it to loopTimeline
                /*
                * Advanced Feature. Enable it in later versions
                $this->saveLoopTimeline(
                    array(
                        $contentStart,
                        $contentEnd,
                        $slicedText,
                        $newLineBreak,
                        $chunk,
                        $beginTrimmedChunk,
                        $endTrimmedChunk,
                        $rtfSymbolsChunk
                    ),
                    $loopTimeline
                );
                */

                // Commit HTML Parsed Chunk to $result
                if (trim($slicedText)) {
                    // Valid non empty text. Will be enclosed inside a span
                    // Also required formatting and line breaks will be added

                    // Pre format slicedText now
                    // HTML Encode
                    // $slicedText = htmlentities($htmlentities, self::HTML_ENCODE_TYPE);

                    // Indentation Concatenation
                    $slicedText = $this->convertIndentationToHtml($futureIndentation) . $slicedText;

                    // TAB replacement
                    $slicedText = str_replace("\\tab", "&emsp;", $slicedText);

                    // Detect styles and add classes
                    $cssClasses = $futureCssClasses;

                    // Stringify css classes
                    $cssClasses = implode(" ", $this->convertRtfStylesToCss($cssClasses, $rtfSymbolsChunk));
                    if (strlen($cssClasses)) {
                        $classString = ' class="' . $cssClasses . '"';
                    } else {
                        $classString = "";
                    }

                    // Check if it's a bulleted or numbered list
                    $listCheck = $this->listCheck($rtfSymbolsChunk, 0);

                    if ($listCheck) {
                        if ($listMatch) {
                            $listLevel = $this->listCheck($rtfSymbolsChunk, 1);
                            if ($listLevel === 1) {
                                $lastListValue = array_pop($listArray);
                                $lastListValue["sub"][] = $slicedText;
                                $listArray[] = $lastListValue;
                            } else {
                                // Level 0 is assumed
                                $listArray[] = array(
                                    "value" => $slicedText,
                                    "sub" => array()
                                );
                            }
                        } else {
                            // List detected for the first time

                            // Set list detect switch to true
                            $listMatch = true;
                            $listType = $this->listCheck($rtfSymbolsChunk, 2);
                            $listArray[] = array(
                                "value" => $slicedText,
                                "sub" => array()
                            );
                        }
                    } else {
                        if (count($listArray) > 0 && $futureLineBreak) {
                            // The list has terminated and normal text has started
                            list($listMatch, $result, $listArray) = $this->terminateList($result, $listArray, $listType);
                        }

                        if ($listMatch && !$futureLineBreak) {
                            // A list was running, but a non list control line occured due to formatting

                            //Append this chunk to the last listArray li element
                            $lastListValue = array_pop($listArray);
                            $lastListValue["value"] = $lastListValue["value"] .
                                "<span" . $classString . ">" . $slicedText . "</span>";
                            $listArray[] = $lastListValue;
                        } else {
                            // Regular text follows
                            // Commit to the final result string
                            $result = $result . ($futureLineBreak ? ("<br>" . PHP_EOL) : "")
                                    . "<span" . $classString . ">" . $slicedText .
                                        "</span>";
                        }
                    }

                    // Detect valid line breaks for future
                    // newline break should lie between previous_content_end and current_content_start
                    list($futureLineBreak, $futureCssClasses) = $this->detectFutureElements($beginTrimmedChunk, $contentEnd);
                    $futureIndentation = "";
                } else {
                    // A line break was found
                    // or will be found in next iteration (special case)

                    // Indicates the case of special indentation
                    // 1 offset because first charachter is a new line
                    if (strpos($slicedText, PHP_EOL, strlen($slicedText) ? 1 : 0) === false) {
                        $futureIndentation = substr($slicedText, 1);
                    } else {
                        $futureIndentation = "";
                    }

                    // Paragraph Division Break Encountered - Wrap result around div tags
                    // Go to the point where last </div> tag exists
                    $lastDivPos = strrpos($result, $this->parseConfig["line_separators"]["close_tag"]);

                    if ($lastDivPos === false) {
                        // Wrap the whole result blob

                        // If an ongoing list was running terminate it (Currently, line breaks in list elements
                        // is not supported)
                        if (count($listArray) > 0 && $listMatch) {
                            // The list has terminated and normal text has started
                            list($listMatch, $result, $listArray) = $this->terminateList($result, $listArray, $listType);
                        }

                        $result = $this->divWrapper($result);
                    } else {
                        // Wrap the last known non div chunk

                        // If an ongoing list was running terminate it (Currently, line breaks in list elements
                        // is not supported)
                        if (count($listArray) > 0 && $listMatch) {
                            // The list has terminated and normal text has started
                            list($listMatch, $result, $listArray) = $this->terminateList($result, $listArray, $listType);
                        }

                        $result = substr($result, 0, $lastDivPos + strlen($this->parseConfig["line_separators"]["close_tag"])) . PHP_EOL . $this->divWrapper(substr($result, $lastDivPos + strlen($this->parseConfig["line_separators"]["close_tag"])));
                    }

                    // Detect valid line breaks for future
                    // newline break should lie between previous_content_end and current_content_start
                    list($futureLineBreak, $futureCssClasses) = $this->detectFutureElements($beginTrimmedChunk, $contentEnd);
                }
                // Reduce the chunk to endTrimmedChunk
                $chunk = $endTrimmedChunk;

            } else {
                $loopSwitch = false;
            }
        } while ($chunk && $loopSwitch);

        return $result;
    }

    /**
     * RTF to HTML code converter
     *
     * @param string $chunk  - RTF Chunk whose text beginning pattern has to be matched
     * @param int    $offset - Offset to start search from
     *
     * @return array $result - content begin style and position
     */
    private function getContentBeginStyle($chunk, $offset = 0)
    {
        // Default beginning style pattern
        $contentBeginStyle = 1;
        $previousPosition = null;
        while (isset($this->parseConfig[self::PARSE_CONFIG_BEGIN_KEY . $contentBeginStyle])) {
            $currentPosition = strpos($chunk, $this->parseConfig[self::PARSE_CONFIG_BEGIN_KEY . $contentBeginStyle]["value"], $offset);
            if ($previousPosition === null) {
                $previousPosition = $currentPosition;
                $contentBeginStyle++;
            } else if ($currentPosition !== false && $previousPosition === $currentPosition) {
                $contentBeginStyle++;
            } else {
                // It means a less specific starter was found
                break;
            }
        }

        $result = array($contentBeginStyle-1, $previousPosition < $currentPosition ? $previousPosition : $currentPosition);

        return $result;
    }

    /**
     * Initializes loop timeline variables with null values
     *
     * @return array $loopTimeline - Array containing timeline variables
     */
    private function intializeLoopTimeline()
    {
        $loopTimeline = array(
            "previous" => array(
                "contentStart" => null,
                "contentEnd" => null,
                "slicedText" => null,
                "newLineBreak" => null,
                "chunk" => null,
                "beginTrimmedChunk" => null,
                "endTrimmedChunk" => null,
                "rtfSymbolsChunk" => null
            ),
            "current" => array(
                "contentStart" => null,
                "contentEnd" => null,
                "slicedText" => null,
                "newLineBreak" => null,
                "chunk" => null,
                "beginTrimmedChunk" => null,
                "endTrimmedChunk" => null,
                "rtfSymbolsChunk" => null
            )
        );

        return $loopTimeline;
    }

    /**
     * Sets loop timeline variables based on input
     *
     * @param array $inpArray     - Array containg the loop variables
     * @param array $loopTimeline - Array containing loop timeline variables set from input array
     *
     * @return array $loopTimeline - Array containing set loop timeline variables
     */
    private function saveLoopTimeline($inpArray, $loopTimeline)
    {
        list(
            $loopTimeline["current"]["contentStart"],
            $loopTimeline["current"]["contentEnd"],
            $loopTimeline["current"]["slicedText"],
            $loopTimeline["current"]["newLineBreak"],
            $loopTimeline["current"]["chunk"],
            $loopTimeline["current"]["beginTrimmedChunk"],
            $loopTimeline["current"]["endTrimmedChunk"],
            $loopTimeline["current"]["rtfSymbolsChunk"]
        ) = $inpArray;

        return $loopTimeline;
    }

    /**
     * Detects if lineBreaks, CSS formatting occur in 1 future iteration
     * To prepare necessary prepended HTML for them
     *
     * @param string $beginTrimmedChunk - Textual line chunk minus the beginning patter portion
     * @param int    $contentEnd        - Postion where this line chunk ends
     *
     * @return array $result - check whether css found or not and their corresponding css styles
     */
    private function detectFutureElements($beginTrimmedChunk, $contentEnd)
    {
        $futureCssClasses = array();
        $remainderFutureChunk = substr($beginTrimmedChunk, $contentEnd);
        // Offset the newLine search by current contentEnd
        $futureNewLineBreak = strpos(
            // Relative to beginTrimmedChunk
            $remainderFutureChunk,
            PHP_EOL
        );

        // Offset the futureContentStart by current contentEnd
        list($contentBeginStyle, $futureContentStart) = $this->getContentBeginStyle($remainderFutureChunk);

        if ($futureNewLineBreak < $futureContentStart) {
            return array(
                true,
                $futureCssClasses
            );
        } else {
            // A formatting style is encountered in cases where there is a false linebreak

            $analyzerString = substr(
                $remainderFutureChunk,
                0,
                // Find a closing braces
                // Offset 1 needed because sometimes the chunk starts with a closing brace
                strpos($remainderFutureChunk, "}", 1)
            );

            $futureCssClasses = $this->convertRtfStylesToCss($futureCssClasses, $analyzerString);

            $result =  array(
                false,
                $futureCssClasses
            );

            return $result;
        }
    }

    /**
     * Converts whitespaces meant for indentation to corresponding HTML encoding
     *
     * @param string $whitespaceString - RTF string containing whitespaces
     *
     * @return string $spaceString - HTML encoded string
     */
    private function convertIndentationToHtml($whitespaceString)
    {
        $len = strlen($whitespaceString);
        $tabs = (int) $len/4;
        $spaces = $len % 4;
        $spaceString = "";
        for ($i=0; $i<$tabs; $i++) {
            $spaceString = $spaceString . "&emsp;";
        }
        for ($i=0; $i<$spaces; $i++) {
            $spaceString = $spaceString . "&nbsp;";
        }

        return $spaceString;
    }

    /**
     * Converts RTF text formatting to CSS
     *
     * @param string $cssClasses      - Current CSS classes already present
     * @param string $rtfSymbolsChunk - RTF symbol control chunk, used for determining styling
     *
     * @return array $cssClasses - CSS classes string
     */
    private function convertRtfStylesToCss($cssClasses, $rtfSymbolsChunk)
    {
        foreach ($this->parseConfig as $key => $value) {
            if (strpos($key, self::PARSE_CONFIG_CHECK_FORMATTING_KEY) !== false) {
                if (strpos($rtfSymbolsChunk, $value["value"]) !== false) {
                    $cssClasses[] = $value["extra"]["css_class"];
                }
            }
        }

        return $cssClasses;
    }

    /**
     * Performs various (list style) checks on a string
     *
     * @param string $string    - RTF Chunk whose text beginning pattern has to be matched
     * @param int    $checkType - Offset to start search from
     *
     * @return mixed $result - Results of various checks
     */
    private function listCheck($string, $checkType = 0)
    {
        switch ($checkType) {
            case 0:
                // Check whether a list or not
                $result = (strpos($string,
                    $this->parseConfig[self::PARSE_CONFIG_CHECK_LIST]["value"]) !== false) ? true : false;

                return $result;

            case 1:
                // Check if at first indentation level
                // Other indentation levels not supported
                $result = (strpos($string,
                    $this->parseConfig[self::PARSE_CONFIG_CHECK_LIST]["extra"]["level_1"]) !== false) ? 1 : 0;

                return $result;

            case 2:
                // Check list wrapper type
                foreach ($this->parseConfig[self::PARSE_CONFIG_CHECK_LIST]["extra"] as $key => $value) {
                    if (strpos($key, "start") !== false) {
                        if (strpos($string, $value["value"]) !== false) {
                            $result = $value["wrapper"];

                            return $result;
                        }
                    }
                }
                $result = "ul";

                return $result;
        }
    }

    /**
     * Processes a list and wraps them into HTML list tags
     *
     * @param string $result    - Result string built upto the current iteration
     * @param array  $listArray - Array containg the list in a stack/nested stack structure
     * @param array  $listType  - Type of list (numbered, bulleted, etc.)
     *
     * @return string $result - Result string containing the list
     */
    private function listProcessor($result, $listArray, $listType)
    {
        // !! Use a Tree Data Structure in future for multiple nested structure

        // Remove trailing <br> from the result tag
        if (strpos(substr($result, -5), "<br>") !== false) {
            $result = substr($result, 0, strrpos($result, "<br>"));
        }

        $listBuffer = "";
        foreach ($listArray as $value) {
            if (count($value["sub"]) == 0) {
                $listBuffer = $listBuffer . "<li>" . $value["value"] . "</li>" . PHP_EOL;
            } else {
                $listLevelOneBuffer = "";
                $listBuffer = $listBuffer . "<li>" . $value["value"] . "</li>" . PHP_EOL;
                foreach ($value["sub"] as $value) {
                    $listLevelOneBuffer = $listLevelOneBuffer . "<li>" . $value . "</li>" . PHP_EOL;
                }
                $listBuffer = $listBuffer . "<" . $listType . ">" . PHP_EOL
                                . $listLevelOneBuffer . "</" . $listType . ">" . PHP_EOL;
            }
        }
        $result = $result . PHP_EOL . "<" . $listType . ">" . PHP_EOL
                    . $listBuffer . "</" . $listType . ">" . PHP_EOL;

        return $result;
    }

    /**
     * Called when a list has terminated and regular code follows
     *
     * @param string $result    - Result string built upto the current iteration
     * @param array  $listArray - Array containg the list in a stack/nested stack structure
     * @param array  $listType  - Type of list (numbered, bulleted, etc.)
     *
     * @return array $result - List termination switch, list appended to rest and emptied list array
     */
    private function terminateList($result, $listArray, $listType)
    {
        $result =  array(
            // Set list detect switch to false
            false,
            // List processing and appending to the result
            $this->listProcessor($result, $listArray, $listType),
            // Empty list array
            array()
        );

        return $result;
    }

    /**
     * Wraps any given string around HTML <div> tags
     *
     * @param string $string - Input string to wrap
     *
     * @return string $result - Result wrapped in <div> tags
     */
    private function divWrapper($string)
    {
        $result = $this->parseConfig["line_separators"]["open_tag"] . PHP_EOL . $string . PHP_EOL . $this->parseConfig["line_separators"]["close_tag"];

        return $result;
    }
}

