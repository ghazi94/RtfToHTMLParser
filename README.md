**Keywords**: RTF Parser, HTML, PHP

# About

A PHP based RTF parser to convert it into corresponding HTML

## Sample Input
[![](https://raw.githubusercontent.com/ghazi94/RtfToHTMLParser/master/RTF.png)](#)
## Sample Output
[![](https://raw.githubusercontent.com/ghazi94/RtfToHTMLParser/master/HTMLOutput.png)](#)

## Sample Usage:

$config = array(
  "sectionSeparation" => array(
    "separator" => "----"
    )
);
$rtfParser = new RtfToHtmlParser($filePath, $config);

<br> 
**More about $config**
<br>
$config['sectionSeparation'] is used to separate the whole RTF document into chunks with easily recognisable separator patterns. 
Also this helps prevent bulk string operations on the whole RTF document and instead processes the document chunk by chunk and returns an array of processed chunks.

<br>
From the code file:
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
