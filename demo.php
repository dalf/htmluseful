<?php
include 'htmluseful.php';
if (isset($_GET['url'])) {
  $url = $_GET['url'];
}
if (count($argv) > 1) {
  $url = $argv[1];
}
if (isset($url)) {
  $useful = htmluseful($url);
}
?><html>
<head>
  <meta charset="UTF-8" >
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="normalize.css">
  <style>
  table td {
    vertical-align: top;
    padding: 2px;
  }
  tr:nth-of-type(odd) { background-color: #ddd; }
  </style>
  <title>htmluseful demo</title>
</head>
  <div style="padding: 10px;">
    <form action="demo.php">
      <input type="text" name="url" length="2048" style="width:90%" value="<? echo $url ?>" autofocus >
      <input type="submit" value="Submit" >
    </form>
  </div>
<?php 
  if (isset($useful)) { 
    echo "<hr>\n";
    echo "<table>\n";
    foreach($useful as $key => $value) {
      if ($key !== 'content') {
	echo "<tr><td>" . $key ."</td><td>" . $value . "</td></tr>\n";
      }
    }
    echo "</table>\n";
    echo "<hr>\n";
    if (array_key_exists('image', $useful)) {
      echo "Image<br/><p><img src=\"".$useful['image']."\" /></p>\n";
    }
    echo "<hr>\n";
    echo "<h1>" . $useful['title'] . "</h1>\n";
    $content_exists = array_key_exists('content', $useful);

    if ($content_exists) {
      echo $useful['content'];
    } else if (array_key_exists('description', $useful)) {
      echo "<p>".$useful['description']."</p>\n";
    }
  }
?>
</html>
