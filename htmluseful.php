<?php

function normalizeString($str) {
  $str = strtolower(trim(html_entity_decode($str)));
  return $str;
}

function fuzzyCompare($str1, $str2, $normalize = FALSE) {
  if ($str1 === $str2) {
    return TRUE;
  }
  if ($str1 === '' || $str2 === '') {
    return FALSE;
  }
  if ($normalize) {
    $str1 = normalizeString($str1);
    $str2 = normalizeString($str2);
  }
  if (strpos($str1, $str2) !== FALSE) {
    return TRUE;
  }
  if (strpos($str2, $str1) !== FALSE) {
    return TRUE;
  }
  if (strlen($str1) < 250 && strlen($str2)) {
    $l = levenshtein($str1, $str2);
    return $l < max(round(strlen($str1)/30), 3);
  } else {
    return FALSE;
  }
}

class HtmlDocument {
  var $document;
  var $xpath;

  function HtmlDocument($content, $document) {
    if (isset($content) && ! isset($document)) {
      libxml_use_internal_errors(true);
      $this->document = new DOMDocument();
      $this->document->preserveWhiteSpace = false;
      $this->document->loadHTML($content, LIBXML_NOBLANKS | LIBXML_COMPACT | LIBXML_NOERROR);
      libxml_use_internal_errors(false);
    } else if (! isset($content) && isset($document) ) {
      $this->document = $document;
    } else {
      // FIXME : throw an exception
    }
    $this->xpath = new DOMXPath($this->document);
  }

  function getContent() {
    $this->document->formatOutput = true;
    return $this->document->saveHTML();
  }
  
}


class ProcessorState {

  public $htmlDocument;
  public $data;

  public function ProcessorState($htmlDocument, $data) {
    $this->htmlDocument = $htmlDocument;
    $this->data = $data;
  }

}


class ProcessorConfiguration {

  private $generic;
  private $perHost;

  public function ProcessorConfiguration() {
    $this->generic = Array();
    $this->perHost = Array();
  }

  public function get($host, $key, $noValueException = TRUE, $defaultValue = NULL) {
    // perHost ?
    if (array_key_exists($host, $this->perHost)) {
      $hostConfiguration = &$this->perHost[$host];
      if (array_key_exists($key, $hostConfiguration)) {
	return $hostConfiguration[$key];
      }
    }

    // generic ?
    if (array_key_exists($key, $this->generic)) {
      return $this->generic[$key];
    }

    // exception ?
    if ($noValueException) {
      throw new Exception("ProcessorConfiguration : No value for key \"$key\" and host \"$host\"" );
    }
    return $defaultValue;
  }

  public function set($key, $value, $host = NULL, $dontReplace = FALSE) {
    $config = &$this->generic;

    if (isset($host)) {
      if (in_array($host, $this->perHost)) {
	$this->perHost[$host] = Array();
      }
      $config = &$this->perHost[$host];
    }

    if ($dontReplace && in_array($key, $config)) {
      return FALSE;
    }
    $config[$key] = $value;
    return TRUE;
  }

}


abstract class HtmlDocumentProcessor {

  public $configuration;

  public function HtmlDocumentProcessor(&$configuration = NULL) {
    if (isset($configuration)) {
      $this->configuration = $configuration;      
    } else {
      $this->configuration = new ProcessorConfiguration();
    }
    $this->setDefaultConfiguration();
  }

  protected function setDefaultConfiguration() {
  }

  public function getConfiguration(&$processorState, $key, $noValueException = TRUE, $defaultValue = NULL) {
    $host = $processorState->data['fetched_host'];
    return $this->configuration->get($host, $key, $noValueException, $defaultValue);
  }

  abstract public function process(&$processorState);

}

/*
  data["baseurl"] contains the base for relative URLs
 */
class HtmlDocumentBaseURLProcessor extends HtmlDocumentProcessor {

  public function process(&$processorState) {
    $baseurl = $processorState->data['url'];
    foreach ($processorState->htmlDocument->xpath->query('/html/head/base') as $element) {
      $baseurl = $element->getAttribute('href');
    }
    $processorState->data["baseurl"] = $baseurl;
  }

}

/*
  Expect that data["title"] contains the title of the document
  Remove meaningless elements and attributes
 */
class HtmlDocumentPruneProcessor extends HtmlDocumentProcessor {

  private static $JUNK_TAGS = Array("style", "form", "iframe", "script", "button", "input", "textarea",
				    "noscript", "select", "option", "object", "applet", "basefont",
				    "bgsound", "blink", "canvas", "command", "menu", "datalist",
				    "embed", "frame", "frameset", "keygen", "label", "marquee", "link",
				    "header", "footer", "nav", "aside", "head" );
  
  private static $JUNK_ATTRS = Array("style", "onclick", "onmouseover", "onload", "onerror", "align", "border", "margin");

  private static $JUNK_XPATH = Array(
				     '//comment()',
				     "//*[contains(concat(' ', normalize-space(@class), ' '), ' robots-nocontent ')]",
				     "//*[@aria-label='tools']",
				     "//*[@aria-hidden='true']",
				     "//*[contains(@style,'display:none') or contains(@style,'visibility:hidden')]",
				     "//*[contains(@style,'display: none') or contains(@style,'visibility: hidden')]",
				     "//*[contains(concat(' ',normalize-space(@class),' '),' entry-utility ')]",
				     "//*[contains(concat(' ',normalize-space(@class),' '),' entry-footer ')]",
				     "//*[contains(concat(' ',normalize-space(@class),' '),' entry-meta ')]",
				     // See https://www.readability.com/publishers/guidelines/#view-plainGuidelines
				     // and http://blog.instapaper.com/post/730281947
				     "//*[contains(concat(' ',normalize-space(@class),' '),' entry-unrelated ')]",
				     "//*[contains(concat(' ',normalize-space(@class),' '),' instapaper_ignore ')]",
				     "//div[@id='nav-below']",
				     "//*[contains(concat(' ',normalize-space(@class),' '),' comment ')]",
				     "//*[contains(concat(' ',normalize-space(@class),' '),' nav ')]",
				     "//*[contains(concat(' ',normalize-space(@class),' '),' navigation ')]",
				     "//*[contains(@class,'forum')]",
				     "//*[contains(@class,'auth')]",
				     "//*[@id='comment']",
				     "//*[@id='comments']"
				     // "//div[@role='menubar' or @role='role='menu' or @role='navigaton' or @role='banner' or @role='search' or @role='complementary' or @role='contentinfo']"
				     );

  protected function setDefaultConfiguration() {
    $this->configuration->set("JUNK_TAGS", self::$JUNK_TAGS);
    $this->configuration->set("JUNK_ATTRS", self::$JUNK_ATTRS);
    $this->configuration->set("JUNK_XPATH", self::$JUNK_XPATH);
  }
  
  public function process(&$processorState) {
    $doc = $processorState->htmlDocument->document;
    $xpath = $processorState->htmlDocument->xpath;

    // remove useless tags
    foreach ($this->getConfiguration($processorState, "JUNK_TAGS") as $tag) {
      self::removeJunkTag($doc, $tag);
    }
  
    // remove useless xpath
    foreach($this->getConfiguration($processorState, "JUNK_XPATH") as $xpathSpec) {
      $elementList = $xpath->query($xpathSpec);
      if ($elementList !== FALSE) {
	foreach ($elementList as $element) {
	  $element->parentNode->removeChild($element);
	}
      } else {
	echo "!! error with $xpathSpec !!";
      }
    }

    // remove useless attributes
    foreach ($this->getConfiguration($processorState, "JUNK_ATTRS") as $attr) {
      self::removeJunkAttr($doc, $attr);
    }
    
    // remove sharer
    self::removeSharer($doc);
    foreach ($xpath->query('//a[@data-remove="data-remove"]') as $node) {
      $node->parentNode->removeChild($node);
    }

    // remove h1/h2... that contains the title document
    self::removeDuplicateTitle($doc, 'h1', $processorState->data["title"]);
    self::removeDuplicateTitle($doc, 'h2', $processorState->data["title"]);
  }
  
  function removeJunkTag($RootNode, $TagName) {
    
    $Tags = $RootNode->getElementsByTagName($TagName);
    
    //Note: always index 0, because removing a tag removes it from the results as well.
    while($Tag = $Tags->item(0)){
      $parentNode = $Tag->parentNode;
      $parentNode->removeChild($Tag);
    }
    
    return $RootNode;
    
  }
  
  function removeJunkAttr($RootNode, $Attr) {
    $Tags = $RootNode->getElementsByTagName("*");
    $i = 0;
    while($Tag = $Tags->item($i++)) {
      $Tag->removeAttribute($Attr);
    }
    // $RootNode->removeAttribute($Attr);
    return $RootNode;
  }
  
  function removeSharer($node) {
    // TODO : convert to configuration
    $elements = $node->getElementsByTagName('a');
    foreach($elements as $element) {
      $href = $element->getAttribute('href');
      if (    (strpos($href, "//www.facebook.com/share") !== FALSE)
	   || (strpos($href, "//twitter.com/intent/tweet") !== FALSE)
	   || (strpos($href, "//twitter.com/home?status=") !== FALSE)
	   || (strpos($href, "//www.linkedin.com/shareArticle") !== FALSE)
	   || (strpos($href, "//www.pinterest.com/pin/create/") !== FALSE)
	   || (strpos($href, "whatsapp://send") === 0)
	   || (strpos($href, "//plus.google.com/share?") !== FALSE)
	   || (strpos($href, "//www.viadeo.com/shareit/") !== FALSE)
	   ) {
	$p = $element->parentNode;
	// FIXME : ugly hack
	$element->setAttribute("data-remove", "data-remove");
      }
    }
    return $node;
  }

  function removeDuplicateTitle($RootNode, $TagName, $Title) {
    $Title = normalizeString($Title);
    
    $Tags = $RootNode->getElementsByTagName($TagName);
    
    $i = 0;
    while($Tag = $Tags->item($i)){
      if (fuzzyCompare(normalizeString($Tag->nodeValue), $Title)) {
	$parentNode = $Tag->parentNode;
	$parentNode->removeChild($Tag);
      } else {
	$i++;
      }
    }
    
    return $RootNode; 
  }
  
}

/*
  Remove empty elements ( for instance  <span></span> )
 */
class HtmlDocumentPruneEmptyProcessor extends HtmlDocumentProcessor {

  private function addScore($node, $score) {
    $doc = $node->ownerDocument;
    while ($node !== $doc) {
      $currentScore = $node->getAttribute("data-score");
      if ($currentScore === "") {
	$currentScore = 0;
      } else {
	$currentScore = intval($currentScore);
      }
      if ($node->nodeName !== 'a') {
	$currentScore += $score;
      }
      $node->setAttribute("data-score", $currentScore);

      $node = $node->parentNode;
    }
  }

  public function process(&$processorState) {
    // remove empty text node
    $xpath = $processorState->htmlDocument->xpath;
    foreach( $xpath->query('//text()') as $node ) {
      $text = $node->nodeValue;
      if (strlen(trim(preg_replace('~[[:cntrl:]]~', '', $text)))===0) {
	$node->parentNode->removeChild($node);
      } else {
	// $this->addScore($node->parentNode, strlen(trim($node->nodeValue)));
      }
    }
     
    // remove empty node (FIXME)
    $doc = $processorState->htmlDocument->document;
    $oneMore = TRUE;
    while ($oneMore) {
      $count = 0;
      $elements = $doc->getElementsByTagName('*');

      foreach($elements as $element) {
	if ( $element->tagName !== 'br' AND $element->tagName !== 'img' AND  $element->tagName !== 'path' AND ( ! $element->hasChildNodes() )) {
	  $element->parentNode->removeChild($element);
	  $count++;
	}
      }
      $oneMore = $count > 0;
    }
  }

}

/*
  Fix relative URLs, <img src="..."> (attempt to read data-src and data-original attributes)
 */
class HtmlDocumentFixURLProcessor extends HtmlDocumentProcessor {

  public function process(&$processorState) {
    $root = $processorState->htmlDocument->document;
    $baseurl = $processorState->data['baseurl'];
    $Tags = $root->getElementsByTagName("img");
    $i = 0;
    $imageCount = 0;
    while($Tag = $Tags->item($i++)) {
      // if src is not defined, try to use data-src
      $src = $Tag->getAttribute("src");
      if ($src === '') {
	$src = $Tag->getAttribute("data-src");
	$Tag->removeAttribute("data-src");
      }
      if ($src === '') {
	// jquery.flexloader
	$src = $Tag->getAttribute("data-original");
	$Tag->removeAttribute("data-orginal");
      }
      if ($src !== '') {
	$Tag->setAttribute('src', self::rel2abs($src, $baseurl));
	$imageCount++;
      }
      $Tag->setAttribute('rel', 'noreferrer');
      // TODO : fix srcset
    }
    $processorState->data["imageCount"] = $imageCount;

    $Tags = $root->getElementsByTagName("a");
    $i = 0;
    $anchorCount = 0;
    while($Tag = $Tags->item($i++)) {
      $Tag->setAttribute('href', self::rel2abs($Tag->getAttribute("href"), $baseurl));
      $Tag->setAttribute('rel', 'noreferrer');
      $anchorCount++;
    } 

    $processorState->data["anchorCount"] = $anchorCount;
  }

  static function rel2abs($rel, $base) {
    /* return if already absolute URL */
    if (parse_url($rel, PHP_URL_SCHEME) != '' || substr($rel, 0, 2) == '//') return $rel;
    
    /* queries and anchors */
    if (strlen($rel) > 0 && ( $rel[0]=='#' || $rel[0]=='?')) return $rel;
    
    /* parse base URL and convert to local variables:
       $scheme, $host, $path */
    extract(parse_url($base));
    
    /* remove non-directory element from path */
    $path = preg_replace('#/[^/]*$#', '', $path);
    
    /* destroy path if relative url points to root */
    if (strlen($rel) > 0 && $rel[0] == '/') $path = '';
    
    /* dirty absolute URL */
    $abs = "$host$path/$rel";
    
    /* replace '//' or '/./' or '/foo/../' with '/' */
    $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
    for($n=1; $n>0; $abs=preg_replace($re, '/', $abs, -1, $n)) {}
    
    /* absolute URL is ready! */
    return $scheme.'://'.$abs;
  }
  
}

/*
  Replace iframe by a link to youtube
 */
class HtmlDocumentYoutubeProcessor extends HtmlDocumentProcessor {

  public function process(&$processorState) {
    $doc = $processorState->htmlDocument->document;
    $Tags = $doc->getElementsByTagName('iframe');
    
    $i = 0;
    while($Tag = $Tags->item($i++)) {
      $src = $Tag->getAttribute("src");
      preg_match("/^(http:|https:|)?\/\/www.youtube.com\/embed\/(.*)/", $src, $output_array);
      if (count($output_array) == 3) {
	$link = 'https://www.youtube.com/watch?v=' . $output_array[2];
	$a = $doc->createElement('a', $link);
	$a->setAttribute('href', $link);
	$parentNode = $Tag->parentNode;
	$parentNode->replaceChild($a, $Tag);
      }
    }

  }

}

/*
  Parse semantic data
 */
class HtmlDocumentMetadataProcessor extends HtmlDocumentProcessor {

  // FIXME : include rel, name, etc...
  // TODO : add FOAF
  private static $META_MAP = [
			      'og:url' => [2, 'url'],
			      'canonical' => [1, 'url'],
			      'og:description' => [10, 'description' ],
			      'twitter:description' => [9, 'description' ],
			      'description' => [0, 'description' ],
			      'og:title' => [10, 'title' ],
			      'twitter:title' => [9, 'title' ],
			      'name' => [8, 'title'],
			      'og:image:secure_url' => [10, 'image'],
			      'og:image' => [9, 'image' ],
			      'twitter:image:src' => [8, 'image'],
			      'og:video:secure_url' => [10, 'video' ],
			      'og:video' => [9, 'video' ]
			      ];

  protected function setDefaultConfiguration() {
    $this->configuration->set("META_MAP", self::$META_MAP);
  }
  
  public function process(&$processorState) {
    // var_dump($this->configuration);
    $META_MAP = $this->getConfiguration($processorState, "META_MAP"); 

    $doc = $processorState->htmlDocument->document;
    $data = &$processorState->data;

    $data_priorities = [ 'url' => 0 ];
    
    $titles = $doc->getElementsByTagName('title');
    if ($titles->length > 0) {
      $data['title'] = $titles->item(0)->textContent;
    }
    $metas = $doc->getElementsByTagName('meta');
    for ($i = 0; $i < $metas->length; $i++) {
      $meta = $metas->item($i);
      $meta_name = $meta->getAttribute('name');
      if ($meta_name === "") {
	$meta_key = "property";
	$meta_name = $meta->getAttribute('property');
      }
      if ($meta_name === "") {
	$meta_key = "itemprop";
	$meta_name = $meta->getAttribute('itemprop');
      }
      if ($meta_name === "") {
	$meta_key = "rel";
	$meta_name = $meta->getAttribute('rel');
      }
      if ($meta_name === "") {
	if ($meta->getAttribute('http-equiv') === 'refresh') {
	  // HACK : http-equiv='refresh' --> follow redirection
	  preg_match("/[0-9]+;URL\=[\"\'](.*)[\"\']/", $meta->getAttribute('content'), $redirect_urls);
	  if (count($redirect_urls) < 1) {
	    preg_match("/[0-9]+;URL\=(.*)/", $meta->getAttribute('content'), $redirect_urls);
	  }
	  if (count($redirect_urls) == 2) {
	    // FIXME : return  htmlpage_getmeta($redirect_urls[1]);
	    return;
	  }
	  // END HACK
	}
	if (strtolower($meta->getAttribute('http-equiv')) === 'content-type') {
	  $data['mimetype'] = $meta->getAttribute('content');
	}
      }
      
      $value = $meta->getAttribute('content');
      if ($value !== '' AND array_key_exists($meta_name, $META_MAP)) {
	$data_name = $META_MAP[$meta_name][1];
	$data_priority = $META_MAP[$meta_name][0];
	$existing_priority = array_key_exists($data_name, $data_priorities)?$data_priorities[$data_name]:-1;
	if ($existing_priority < $data_priority) {
	  $data[$data_name] = $meta->getAttribute('content');
	  $data_priorities[$data_name] = $data_priority;
	}
	// add all og: data
      }
    }
    
    if (isset($data["description"]) && fuzzyCompare($data["title"], $data["description"], TRUE)) {
      unset($data["description"]);
    }
    
  }

}

/*
  Replace the document by a subset containing only the text of the article
 */
class HtmlDocumentContentProcessor extends HtmlDocumentProcessor {

  private static $FORBIDDEN_HOSTS_CONTENT = [ 'twitter.com', 'www.twitter.com', 
					      'www.reddit.com', '8tracks.com', 'www.bizjournals.com',
					      'www.amazon.com'];

  private static $XPATH = Array(
		      "//*[@itemprop='reviewBody']",
		      "//*[contains(concat(' ', normalize-space(@itemprop), ' '), ' articleBody ')]",
		      "//article",
		      "//main[@role='main']",
		      "//div[@role='main']",
		      "//div[contains(concat(' ', normalize-space(@class), ' '), ' story-body ')]",
		      "//article[@id='story']",
		      "//div[@id='story']",
		      "//*[contains(concat(' ',normalize-space(@class),' '),' instapaper_body ')]",
		      "//*[contains(concat(' ',normalize-space(@class),' '),' hentry ')]",
		      // WordPress
		      "//div[contains(concat(' ', normalize-space(@class), ' '), ' entry-content ')]",
		      // SPIP
		      "//div[contains(concat(' ', normalize-space(@class), ' '), ' article-contenu ')]",
		      "//*[contains(concat(' ', normalize-space(@class), ' '), ' post-content ')]",
		      "//div[contains(concat(' ', normalize-space(@class), ' '), ' article-content ')]",
		      "//div[contains(concat(' ', normalize-space(@class), ' '), ' article-text ')]",
		      // "//*[contains(concat(' ', normalize-space(@class), ' '), ' content ')]",
		      "//div[@id='single']",
		      "//div[@id='content']",
		      // http://www.agoravox.tv/
		      "//div[@id='article']/div[@class='cadretexte']"
		      );

  protected function setDefaultConfiguration() {
    $this->configuration->set("CONTENT_XPATH", self::$XPATH);
    foreach (self::$FORBIDDEN_HOSTS_CONTENT as $host) {
      $this->configuration->set("DISALLOW_CONTENT", TRUE, $host);
    }
  }
  
  public function process(&$processorState) {
    if ($this->getConfiguration($processorState, "DISALLOW_CONTENT", FALSE, FALSE)) {
      return FALSE;
    }
    list($node, $xpathSpec) = $this->getContentNode($processorState);

    if ($node !== NULL) {
      $processorState->data["contentXPath"] = $xpathSpec;

      // 
      $doc = $processorState->htmlDocument->document;
      $xpath = $processorState->htmlDocument->xpath;

      // move node at the end of the document
      $doc->appendChild($node);

      // remove everything else
      foreach($xpath->query("/processing-instruction") as $child) {  
	$child->parentNode->removeChild($child);
      }

      foreach($xpath->query("/child::node()") as $child) {  
	if ($child !== $node) {
	  $child->parentNode->removeChild($child);
	}
      }

      return TRUE;
    } else {
      return FALSE;
    }
  }

  public function getContentNode(&$processorState) {
    $contentBodies = NULL;
    $contentXpath ="";
    foreach($this->getConfiguration($processorState, "CONTENT_XPATH") as $xpathSpec) {
      $contentBodies = $processorState->htmlDocument->xpath->query($xpathSpec);
      if ($contentBodies->length > 0) {
	$node = $contentBodies->item(0);
	return [ $node, $xpathSpec ];
      }
    }
    return [ NULL, NULL ];
  }

}

class HtmlDocumentAllProcessor extends HtmlDocumentProcessor {

  private static $HTMLPAGE_MIMETYPE = 'text/html';
  private static $FORBIDDEN_HOSTS = [ 'www.youtube.com' ];

  private $processors = Array();

  protected function setDefaultConfiguration() {
    foreach(self::$FORBIDDEN_HOSTS as $host) {
      $this->configuration->set("DISABLED", TRUE, $host);
    }

    $this->processors['BaseURL'] = new HtmlDocumentBaseURLProcessor($this->configuration);
    $this->processors['Metadata'] = new HtmlDocumentMetadataProcessor($this->configuration);
    $this->processors['Content'] = new HtmlDocumentContentProcessor($this->configuration);
    $this->processors['Youtube'] = new HtmlDocumentYoutubeProcessor($this->configuration);
    $this->processors['FixURL'] = new HtmlDocumentFixURLProcessor($this->configuration);
    $this->processors['Prune'] = new HtmlDocumentPruneProcessor($this->configuration);
    $this->processors['PruneEmpty'] = new HtmlDocumentPruneEmptyProcessor($this->configuration);
  }

  public function process(&$processorState) {
    if ($processorState->htmlDocument != NULL) {
      if (strpos($processorState->data['mimetype'], self::$HTMLPAGE_MIMETYPE) === 0 
	  && ! $this->getConfiguration($processorState, "DISABLED", FALSE, FALSE)) {
	// FIXME ??
	$this->processors['BaseURL']->process($processorState);
	$this->processors['Metadata']->process($processorState);
	$this->processors['FixURL']->process($processorState);
	$this->processors['Youtube']->process($processorState);

	$this->processors['Prune']->process($processorState);
	$this->processors['PruneEmpty']->process($processorState);

	// echo $processorState->htmlDocument->getContent();
	// throw new Exception('');

	// TODO : if no image then add the (twitter|og):image at the top
	if ($this->processors['Content']->process($processorState)) {
	  // HACK ?
	  $processorState->data['content'] = $processorState->htmlDocument->getContent();
	} else {
	  // ??
	  $processorState->data['content'] = $processorState->htmlDocument->getContent();
	}
      }
    }
  }

}

/*
  Create a ProcessorState for a URL using CURL
 */
class URLProcessorStateFactory {

  // raw HTML filters
  protected static $pre_filters = array(
				 '!<script[^>]*>(.*?)</script>!is' => '', // remove obvious scripts
				 '!<style[^>]*>(.*?)</style>!is' => '', // remove obvious styles
				 '!</?span[^>]*>!is' => '', // remove spans as we redefine styles and they're probably special-styled
				 '!<font[^>]*>\s*\[AD\]\s*</font>!is' => '', // HACK: firewall-filtered content
				 '!(<br[^>]*>[ \r\n\s]*){2,}!i' => '</p><p>', // HACK: replace linebreaks plus br's with p's
				 //'!</?noscript>!is' => '', // replace noscripts
				 '!<(/?)font[^>]*>!is' => '<\\1span>', // replace fonts to spans
				 );
  
  public static function create($url) {
    $data = [ 'url' => $url ];
    
    list($httpcode, $url, $content, $mimetype) = self::fetch($url);

    // FIXME : content_type
    $data['mimetype'] = $mimetype;
    $data['url'] = $url;
    $data['fetched_url'] = $url;
    $data['fetched_host'] = parse_url($url, PHP_URL_HOST);
    
    if ($content !== FALSE && $httpcode === 429) {
      return new ProcessorState(NULL, $data);
    }
    
    if ($content !== FALSE && $httpcode >= 200 && $httpcode < 300) {
      // decode
      self::decodeContent($content, '');

      // prefilter
      foreach (self::$pre_filters as $search => $replace) {
	$content = preg_replace($search, $replace, $content);
      }

      return new ProcessorState(new HtmlDocument($content, NULL), $data);
    }
    
    return new ProcessorState(NULL, $data);
  }
  
  private static function fetch($url) {
    $ch = curl_init("$url");
    
    curl_setopt($ch, CURLOPT_HEADER, 0); 
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
    curl_setopt($ch, CURLOPT_RANGE, '0-65536');
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/47.0.2526.106 Safari/537.36');
    // alllow cookies between potential redirects
    curl_setopt($ch, CURLOPT_COOKIEFILE, '');
    
    $content = curl_exec($ch); 
    $url = curl_getinfo ($ch, CURLINFO_EFFECTIVE_URL);
    $mimetype = curl_getinfo ($ch, CURLINFO_CONTENT_TYPE);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // $content= html_entity_decode($content);
    
    return array($httpcode, $url, $content, $mimetype);
  }

  private static function decodeContent(&$content, $contentType) {
    $encoding = self::getEncoding($content, $contentType);
    if ($encoding !== '' && $encoding !== 'UTF-8') {
      $content = mb_convert_encoding($content, $encoding, 'UTF-8');
    }
    return $encoding;
  }

  private static function getEncoding(&$content, $contentType) {
    preg_match("/\<meta http-equiv=\"[cC]ontent-[tT]ype\" content=\"([^\"]*)[Cc][Hh][Aa][Rr][Ss][Ee][Tt]=([^\";]*)/", $content, $regexoutput);
    
    $encoding = ''; // FIXME : use contentType
    if (count($regexoutput) > 0) {
      $encoding = self::normalizeEncoding($regexoutput[2]);
    }
    preg_match('/<meta charset=\"([^\"]*)\">/', $content, $regexoutput);
    if (count($regexoutput) > 0) {
      $encoding = self::normalizeEncoding($regexoutput[1]);
    }
    if ($encoding !== '' && $encoding !== 'UTF-8') {
      $content = mb_convert_encoding($content, $encoding, 'UTF-8');
    }
    
    return $encoding;
  }

  private static function normalizeEncoding($encodingLabel)
  {
    $encoding = strtoupper($encodingLabel);
    $encoding = preg_replace('/[^a-zA-Z0-9\s]/', '', $encoding);
    $equivalences = array(
			  'ISO88591' => 'ISO-8859-1',
			  'ISO8859'  => 'ISO-8859-1',
			  'ISO'      => 'ISO-8859-1',
			  'LATIN1'   => 'ISO-8859-1',
			  'LATIN'    => 'ISO-8859-1',
			  'UTF8'     => 'UTF-8',
			  'UTF'      => 'UTF-8',
			  'WIN1252'  => 'ISO-8859-1',
			  'WINDOWS1252' => 'ISO-8859-1'
			  );
    if(empty($equivalences[$encoding])){
      return 'UTF-8';
    }
    return $equivalences[$encoding];
  }
    
}

function htmluseful($url) {
  $processorConfiguration = new ProcessorConfiguration();
  $processor = new HtmlDocumentAllProcessor($processorConfiguration);

  $processorState = URLProcessorStateFactory::create($url);
  $processor->process($processorState);

  return $processorState->data;
}

?>
